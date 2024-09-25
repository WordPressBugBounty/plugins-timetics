<?php
/**
 * WooCommerce Coupon Restriction Option Template
 *
 * @package Timetics
 */

$selected_ids = get_post_meta( $coupon_id, 'timetics_meeting_ids', true );
$selected_ids = ! empty( $selected_ids ) ? $selected_ids : [];

?>
<p class="form-field">
    <label><?php esc_html_e( 'Timetics Meetings', 'timetics' ); ?></label>
    <select class="timetics-product-search" multiple="multiple" style="width: 50%;" name="timetics_meeting_ids[]" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'timetics' ); ?>">
        <?php
        $args = array(
            'status' => 'publish',
            'limit' => -1,
            'category' => array( 'timetics-meeting' ),
        );
        $products = wc_get_products( $args );

        foreach ( $products as $product ) {
            $selected = in_array( $product->get_id(), $selected_ids );
            if ( is_object( $product ) ) {
                echo '<option value="' . esc_attr( $product->get_id() ) . '"' . selected( $selected, true, false ) . '>' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
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
