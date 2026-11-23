<?php

/**
 * Admin meni class
 *
 * @package Timetics
 */

namespace Timetics\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Menu
 */
class Menu
{

    use \Timetics\Utils\Singleton;

    /**
     * Initialize
     *
     * @return void
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'register_admin_menu'));

        add_action('timetics_menu', array($this, 'add_pro_admin_menu'));
    }


    /**
     * Register admin menu
     *
     * @return void
     */

    public function register_admin_menu()
    {
        global $submenu;
        $capability = 'manage_timetics';
        $slug       = 'timetics';
        $url        = 'admin.php?page=' . $slug . '#';
        $menu_items = array(
            [
                'id'         => 'overview',
                'title'      => esc_html__('Overview', 'timetics'),
                'link'       => '/',
                'capability' => apply_filters('timetics_menu_permission_overview', $capability),
                'position'   => apply_filters('timetics_menu_position_overview', 1),
            ],
            [
                'id'         => 'ask-ai',
                'title'      => esc_html__('Ask AI', 'timetics'),
                'link'       => '/ask-ai',
                // Staff cannot set Aisentic up, so they only get the page once the assistant is ready.
                'capability' => apply_filters('timetics_menu_permission_ask_ai', class_exists('Aisentic\Init') && timetics_aisentic_is_registered() ? $capability : 'manage_options'),
                'position'   => apply_filters('timetics_menu_position_ask_ai', 1.2),
            ],
            [
                'id'         => 'meeting',
                'title'      => esc_html__('Meetings', 'timetics'),
                'link'       => '/meetings',
                'capability' => apply_filters('timetics_menu_permission_meeting', 'read_booking'),
                'position'   => apply_filters('timetics_menu_position_meeting', 2),
            ],
            [
                'id'         => 'staff',
                'title'      => esc_html__('Team Members', 'timetics'),
                'link'       => '/staff',
                'capability' => apply_filters('timetics_menu_permission_staff', 'edit_staff'),
                'position'   => apply_filters('timetics_menu_position_staff', 3),
            ],

            [
                'id'         => 'booking',
                'title'      => esc_html__('Calendar', 'timetics'),
                'link'       => '/bookings',
                'capability' => apply_filters('timetics_menu_permission_booking', 'read_booking'),
                'position'   => apply_filters('timetics_menu_position_booking', 1.5),
            ],
            [
                'id'         => 'customer',
                'title'      => esc_html__('Customers', 'timetics'),
                'link'       => '/customers',
                'capability' => apply_filters('timetics_menu_permission_customer', 'manage_options'),
                'position'   => apply_filters('timetics_menu_position_customer', 5),
            ],
            [
                'id'         => 'settings',
                'title'      => esc_html__('Settings', 'timetics'),
                'link'       => '/settings',
                'capability' => apply_filters('timetics_menu_permission_settings', 'manage_options'),
                'position'   => apply_filters('timetics_menu_position_settings', 6),
            ],
            array(
                'id'         => 'mcp-server',
                'title'      => esc_html__( 'MCP Server', 'timetics' ),
                'link'       => '/mcp-server',
                'capability' => apply_filters( 'timetics_menu_permission_mcp_server', 'manage_options' ),
                'position'   => apply_filters( 'timetics_menu_position_mcp_server', 6.5 ),
            ),
            [
                'id'         => 'my-profile',
                'title'      => esc_html__('My Profile', 'timetics'),
                'link'       => '/my-profile',
                'capability' => apply_filters('timetics_menu_permission_my_profile', 'edit_profile'),
                'position'   => apply_filters('timetics_menu_position_my_profile', 7),
            ],
            [
                'id'         => 'shortcode',
                'title'      => esc_html__('Shortcodes', 'timetics'),
                'link'       => '/shortcodes',
                'capability' => apply_filters('timetics_menu_permission_shortcode', 'manage_options'),
                'position'   => apply_filters('timetics_menu_position_shortcode', 8),
            ],
            [
                'id'         => 'onboard',
                'title'      => esc_html__('Setup Wizard', 'timetics'),
                'link'       => '/onboard',
                'capability' => apply_filters('timetics_menu_permission_onboard', 'manage_options'),
                'position'   => apply_filters('timetics_menu_position_onboard', 9),
            ],
            [
                'id'         => 'about-us',
                'title'      => esc_html__('About Us', 'timetics'),
                'link'       => '/about-us',
                'capability' => apply_filters('timetics_menu_permission_about_us', 'manage_options'),
                'position'   => apply_filters('timetics_menu_position_about_us', 10),
            ],
        );

        add_menu_page(
            esc_html__( 'WPTimetics', 'timetics' ),
            esc_html__( 'WPTimetics', 'timetics' ),
            $capability,
            $slug,
            array($this, 'timetics_dashboard_view'),
            // A file URL, not inline SVG: WordPress repaints inline menu icons in one
            // color, which would flatten this two-color logo into a solid block.
            TIMETICS_ASSETS_URL . 'images/menu-icon.svg',
            10
        );

        $menu_items = apply_filters('timetics_menu', $menu_items);
        $position   = array_column($menu_items, 'position');

        array_multisort($position, SORT_ASC, $menu_items);

        foreach ($menu_items as $item) {
            $external = !empty($item['external_link']) ? $item['external_link'] : false;

            $link = !$external ? $url . $item['link'] : $item['link'];
            $submenu[$slug][] = [$item['title'], $item['capability'], $link]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        }
    }

    /**
     * Admin dashboard view
     *
     * @return void
     */
    public function timetics_dashboard_view()
    {
?>
        <div class="wrap" id="time_tics_dashboard">
        </div>
<?php
    }

    // Add submenu page under "Settings"
    public function add_pro_admin_menu($menu_items)
    {
        if (class_exists('TimeticsPro')) {
            return $menu_items;
        }

        $menu_items[] = [
            'id'         => 'go-pro',
            'title'      => esc_html__('Go Pro', 'timetics'),
            'link'       => 'https://arraytics.com/timetics',
            'external_link' => true,
            'capability' => 'manage_options',
            'position'   => 9,
        ];

        return $menu_items;
    }
}
