<?php
/**
 * Service Taxonomy
 *
 * @package Timetics
 */
namespace Timetics\Core\Services;

use Timetics\Base\Taxonomy;

/**
 * Service Taxonomy
 */
class Service_Taxonomy extends Taxonomy {

    /**
     * Set taxonomy props
     *
     * @return  void
     */
    public function set_props() {
        $this->name = 'timetics-service';
        $this->cpt  = 'timetics-appointment';

        $this->args = $this->taxonomy();
    }

    /**
     * Set taxonomy args
     *
     * @return  array
     */
    public function taxonomy() {
        $labels = [
            'name'              => esc_html__( 'Category', 'timetics' ),
            'singular_name'     => esc_html__( 'Category', 'timetics' ),
            'search_items'      => esc_html__( 'Search Category', 'timetics' ),
            'all_items'         => esc_html__( 'All Category', 'timetics' ),
            'parent_item'       => esc_html__( 'Parent Category', 'timetics' ),
            'parent_item_colon' => esc_html__( 'Parent Category:', 'timetics' ),
            'edit_item'         => esc_html__( 'Edit Category', 'timetics' ),
            'update_item'       => esc_html__( 'Update Category', 'timetics' ),
            'add_new_item'      => esc_html__( 'Add New Category', 'timetics' ),
            'new_item_name'     => esc_html__( 'New Category Name', 'timetics' ),
            'menu_name'         => esc_html__( 'Category', 'timetics' ),
        ];

        $args = [
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_admin_column' => true,
            'public'            => true,
            'show_ui'           => false,
            'show_in_nav_menus' => false,
            'show_in_menu'      => false,
            'query_var'         => true,
            'show_in_rest'      => true,
        ];

        return $args;
    }
}
