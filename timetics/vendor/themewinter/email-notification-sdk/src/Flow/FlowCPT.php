<?php
namespace Ens\Flow;

use Ens\Config;
use Ens\Utils\Helpers;

/**
 * Class FlowCPT
 *
 * @package Ens\Flow
 *
 * @since 1.0.0
 */
class FlowCPT {

    protected $identifier;

    /**
     * Register post type.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register($identifier) {
        $this->identifier = $identifier;
        add_action( 'init', [$this, 'register_post_type'] );
    }

    /**
     * Register post type.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_post_type() {
        $plugin_name    = Helpers::get_config_data( $this->identifier,'plugin_name' );
        $prefix_for_cpt = Helpers::get_config_data( $this->identifier,'general_prefix' );
        $text_domain    = Helpers::get_config_data( $this->identifier,'text_domain' );

        $labels = array(
            'name'               => __( $plugin_name . ' Notification Flow', $text_domain ),
            'singular_name'      => __( $plugin_name . ' Notification Flow', $text_domain ),
            'menu_name'          => __( $plugin_name . ' Notification Flow', $text_domain ),
            'add_new'            => __( 'Add New', $text_domain ),
            'add_new_item'       => __( 'Add New ' . $plugin_name . ' Notification Flow', $text_domain ),
            'edit'               => __( 'Edit', $text_domain ),
            'edit_item'          => __( 'Edit ' . $plugin_name . ' Notification Flow', $text_domain ),
            'new_item'           => __( 'New ' . $plugin_name . ' Notification Flow', $text_domain ),
            'view'               => __( 'View', $text_domain ),
            'view_item'          => __( 'View ' . $plugin_name . ' Notification Flow', $text_domain ),
            'search_items'       => __( 'Search ' . $plugin_name . ' Notification Flow', $text_domain ),
            'not_found'          => __( 'No ' . $plugin_name . ' notification flow found', $text_domain ),
            'not_found'          => __( 'No ' . $plugin_name . ' notification flow found', $text_domain ),
            'not_found_in_trash' => __( 'No ' . $plugin_name . ' notification flow found in trash', $text_domain ),
            'parent'             => __( 'Parent ' . $plugin_name . ' notification flow', $text_domain ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => $prefix_for_cpt . '-flow' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
        );

        register_post_type(
            $prefix_for_cpt . '-flow',
            $args
        );
    }
}
