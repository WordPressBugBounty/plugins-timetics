<?php
/**
 * Single Meeting Template
 *
 * @package Timetics
 */ 
 wp_head();
 $id = $post->ID;
 ?>
 <div class="timetics-single-page-wrapper">
        <div class="timetics-container">
            <?php
                if(!empty($id)){ 
                    echo do_shortcode('[timetics-booking-form id='.$id.']');
                }
            ?>
        </div>
 </div> 
 <?php
 wp_footer();