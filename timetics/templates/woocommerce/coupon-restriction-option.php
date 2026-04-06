<?php
/**
 * WooCommerce Coupon Restriction Option Template
 *
 * @package Timetics
 */

defined( 'ABSPATH' ) || exit;

$timetics_selected_ids = get_post_meta( $coupon_id, 'timetics_meeting_ids', true );
$timetics_selected_ids = ! empty( $timetics_selected_ids ) ? $timetics_selected_ids : [];

// Add nonce field for security
wp_nonce_field( 'timetics_coupon_action', 'timetics_coupon_nonce' );

?>
<p class="form-field">
    <label><?php esc_html_e( 'Timetics Meetings', 'timetics' ); ?></label>
    <select class="timetics-product-search" multiple="multiple" style="width: 50%;" name="timetics_meeting_ids[]" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'timetics' ); ?>">
        <?php
        $timetics_args = array(
            'status' => 'publish',
            'limit' => -1,
            'category' => array( 'timetics-meeting' ),
        );
        $timetics_products = wc_get_products( $timetics_args );

        foreach ( $timetics_products as $timetics_product ) {
            $timetics_selected = in_array( $timetics_product->get_id(), $timetics_selected_ids );
            if ( is_object( $timetics_product ) ) {
                echo '<option value="' . esc_attr( $timetics_product->get_id() ) . '"' . selected( $timetics_selected, true, false ) . '>' . esc_html( wp_strip_all_tags( $timetics_product->get_formatted_name() ) ) . '</option>';
            }
        }
        ?>
    </select>
    <?php echo wc_help_tip( __( 'Products that the coupon will be applied to, or that need to be in the cart in order for the "Fixed cart discount" to be applied.', 'timetics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</p>
<script>
    jQuery(function($) {
        $('.timetics-product-search').select2();
    });
</script>
