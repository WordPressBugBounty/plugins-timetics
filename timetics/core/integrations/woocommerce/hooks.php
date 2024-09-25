<?php
/**
 * WooCommerce Hooks Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Woocommerce;

use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Customers\Customer;
use Timetics\Utils\Singleton;
use Timetics\Core\Emails\New_Event_Email;
use Timetics\Core\Emails\New_Event_Customer_Email;

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

        add_filter( 'timetics_get_settings', [$this, 'add_woocommerce_currency_support'] );

        add_filter( 'woocommerce_product_query', [$this, 'modify_woocommerce_shop'] );

        add_action( 'woocommerce_coupon_options_usage_restriction', [$this, 'add_meeting_coupon_restriction_option'], 10, 2 );

        add_action( 'woocommerce_coupon_options_save', [$this, 'save_coupon_option'], 10, 2 );

        add_filter( 'woocommerce_coupon_get_products', [$this, 'assign_meeting_coupon_products'], 10, 2 );

        add_filter( 'timetics_settings', [$this, 'add_wc_checkout_url'] );

        add_filter( 'woocommerce_checkout_fields', [$this, 'hide_checkout_fields'] );

        add_filter( 'woocommerce_checkout_posted_data', [$this, 'modify_order_data'] );

        add_action( 'timetics_after_booking_create', [$this, 'add_product_to_cart'], 10, 4 );

        add_filter( 'timetics_currency', [ $this, 'support_woocommerce_currency' ] );

        add_action( 'admin_init', [ $this, 'create_term_timetics_meeting' ] );

        add_action( 'wp_head', [ $this, 'hide_regular_price_checkout_css' ] );

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
            WC()->session->set( 'timetics_data', null );
            return;
        }

        $session_data = WC()->session->get( 'timetics_data' );

        if ( ! $session_data ) {
            return;
        }

        $booking = new Booking( $session_data['booking_id'] );

        $booking->update( [
            'post_status'    => 'completed',
            'payment_status' => 'completed',
            'payment_method' => 'woocommerce',
        ] );

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
        $meeting_ids = ! empty( $_POST['timetics_meeting_ids'] ) ? array_map( 'intval', $_POST['timetics_meeting_ids'] ) : [];

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


        // Set session for timetics data for woocommerce.
        WC()->session->set( 'timetics_data', [
            'booking_id' => $booking_id,
            'meeting_id' => $meeting_id,
            'price'      => $price,
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
}
