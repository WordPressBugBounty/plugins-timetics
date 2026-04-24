<?php
/**
 * Template Name: Custom Archive Template
 */

defined( 'ABSPATH' ) || exit;

 wp_head();
 $timetics_category = get_queried_object();
 
 ?>
 <div class="timetics-single-page-wrapper timetics-category-meeting">
    <div class="timetics-container">
            <?php
                if ( ! empty( $timetics_category ) ) {
                    echo do_shortcode( '[timetics-category id=' . absint( $timetics_category->term_id ) . ']' );
                }
            ?>
    </div>
 </div> 
 <?php
  wp_footer();