<?php
/**
 * Booking related hooks
 *
 * @package Timetics
 */

namespace Timetics\Core\Appointments;

use Timetics\Utils\Singleton;

class Hooks {
    use Singleton;

    /**
     * Initialize
     *
     * @return void
     */
    public function init() {
        add_filter( 'template_include', [ $this, 'custom_single_post_template' ] );
        add_action( 'init', [ $this, 'register_appointment' ] );
        add_action( 'init', [ $this, 'register_appointment_taxonomy' ] );
        // add_filter( 'get_term', [ $this, 'prepare_term' ], 10, 2 );

    } 

    /**
     * Include custom template for single appointment
     *
     * @param   string  $template
     *
     * @return  string
     */
    public function custom_single_post_template( $template ) {
        

        // Check if it's a single post
        if ( is_single() ) { 
            global $post; // Add this line to access the global $post variable
    
            // Check if the post type is 'timetics-appointment'
            if ('timetics-appointment' !== $post->post_type) {
                return $template;
            }
            // Load a custom template file
            $custom_template = TIMETICS_PLUGIN_DIR . '/templates/meeting/custom-single-post-template.php';

            if ( file_exists( $custom_template ) ) {
                return $custom_template;
            }

        }

        // Check if it's an archive
        
        if (is_archive()  && is_tax('timetics-meeting-category')) {
            
            // Check if the post type is 'timetics-appointment'
            $custom_template = TIMETICS_PLUGIN_DIR . '/templates/meeting/category-template.php';
            
            // If a custom template exists, use it; otherwise, use the default template
            if (file_exists($custom_template)) {
                return $custom_template;
            }
            
        }

        // Return the original template if no custom template is found
        return $template;
    }

    /**
     * Register custom post type for appointment
     *
     * @return  void
     */
    public function register_appointment() {
        $labels = array(
            'name'               => __( 'Timetics Appointments', 'timetics' ),
            'singular_name'      => __( 'Timetics Appointment', 'timetics' ),
            'menu_name'          => __( 'Timetics Appointments', 'timetics' ),
            'add_new'            => __( 'Add New', 'timetics' ),
            'add_new_item'       => __( 'Add New Timetics Appointment', 'timetics'),
            'edit'               => __( 'Edit', 'timetics' ),
            'edit_item'          => __( 'Edit Timetics Appointment', 'timetics' ),
            'new_item'           => __( 'New Timetics Appointment', 'timetics' ),
            'view'               => __( 'View', 'timetics' ),
            'view_item'          => __( 'View Timetics Appointment', 'timetics' ),
            'search_items'       => __( 'Search Timetics Appointments', 'timetics' ),
            'not_found'          => __( 'No timetics appointments found', 'timetics' ),
            'not_found_in_trash' => __( 'No timetics appointments found in trash', 'timetics' ),
            'parent'             => __( 'Parent Timetics Appointment', 'timetics' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'timetics-appointment' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
        );

        register_post_type( 'timetics-appointment', $args );
    }

    public function register_appointment_taxonomy() {
        $labels = array(
            'name'                       => __( 'Appointment categories', 'timetics' ),
            'singular_name'              => __( 'Appointment category', 'timetics' ),
            'menu_name'                  => __( 'Appointment category', 'timetics' ),
            'all_items'                  => __( 'Appointment categories', 'timetics' ),
            'parent_item'                => __( 'Parent Genre', 'timetics' ),
            'parent_item_colon'          => __( 'Parent Genre:', 'timetics' ),
            'new_item_name'              => __( 'New Genre Name', 'timetics' ),
            'add_new_item'               => __( 'Add New Genre', 'timetics' ),
            'edit_item'                  => __( 'Edit Genre', 'timetics' ),
            'update_item'                => __( 'Update Genre', 'timetics' ),
            'view_item'                  => __( 'View Genre', 'timetics' ),
            'separate_items_with_commas' => __( 'Separate genres with commas', 'timetics' ),
            'add_or_remove_items'        => __( 'Add or remove genres', 'timetics' ),
            'choose_from_most_used'      => __( 'Choose from the most used genres', 'timetics' ),
            'popular_items'              => __( 'Popular Genres', 'timetics' ),
            'search_items'               => __( 'Search Genres', 'timetics' ),
            'not_found'                  => __( 'Genre Not Found', 'timetics' ),
            'no_terms'                   => __( 'No genres', 'timetics' ),
            'items_list'                 => __( 'Genres list', 'timetics' ),
            'items_list_navigation'      => __( 'Genres list navigation', 'timetics' ),
        );

        $args = array(
            'labels'                     => $labels,
            'hierarchical'               => true,
            'public'                     => true,
            'show_ui'                    => false,
            'show_admin_column'          => true,
            'show_in_nav_menus'          => true,
            'show_tagcloud'              => true,
        );

        register_taxonomy( 'timetics-meeting-category', array( 'book' ), $args ); //
    }

    public function prepare_term( $term, $taxonomy ) {
        if ( 'timetics-meeting-category' !== $taxonomy ) {
            return $term;
        }


        return $term;
    }
}
