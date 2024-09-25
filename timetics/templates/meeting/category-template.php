<?php
/**
 * Template Name: Custom Archive Template
 */
 
 wp_head();
 $category = get_queried_object();
 
 ?>
 <div class="timetics-single-page-wrapper timetics-category-meeting">
    <div class="timetics-container">
            <?php
                if(!empty($category)){ 
                    echo do_shortcode('[timetics-category id='.$category->term_id.']');
                }
            ?>
    </div>
 </div> 
 <?php
  wp_footer();