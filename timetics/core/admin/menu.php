<?php

/**
 * Admin meni class
 *
 * @package Timetics
 */

namespace Timetics\Core\Admin;

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
        $timetics_menu_position = 'timetics_menu_position_';
        $timetics_menu_permission = 'timetics_menu_permission_';

        $menu_items = array(
            [
                'id'         => 'overview',
                'title'      => esc_html__('Overview', 'timetics'),
                'link'       => '/',
                'capability' => apply_filters($timetics_menu_permission . 'overview', $capability),
                'position'   => apply_filters($timetics_menu_position . 'overview', 1),
            ],
            [
                'id'         => 'meeting',
                'title'      => esc_html__('Meetings', 'timetics'),
                'link'       => '/meetings',
                'capability' => apply_filters($timetics_menu_permission . 'meeting', 'read_booking'),
                'position'   => apply_filters($timetics_menu_position . 'meeting', 2),
            ],
            [
                'id'         => 'staff',
                'title'      => esc_html__('Team Members', 'timetics'),
                'link'       => '/staff',
                'capability' => apply_filters($timetics_menu_permission . 'staff', 'edit_staff'),
                'position'   => apply_filters($timetics_menu_position . 'staff', 3),
            ],

            [
                'id'         => 'booking',
                'title'      => esc_html__('Calendar', 'timetics'),
                'link'       => '/bookings',
                'capability' => apply_filters($timetics_menu_permission . 'booking', 'read_booking'),
                'position'   => apply_filters($timetics_menu_position . 'booking', 1.5),
            ],
            [
                'id'         => 'customer',
                'title'      => esc_html__('Customers', 'timetics'),
                'link'       => '/customers',
                'capability' => apply_filters($timetics_menu_permission . 'customer', 'manage_options'),
                'position'   => apply_filters($timetics_menu_position . 'customer', 5),
            ],
            [
                'id'         => 'settings',
                'title'      => esc_html__('Settings', 'timetics'),
                'link'       => '/settings',
                'capability' => apply_filters($timetics_menu_permission . 'settings', 'manage_options'),
                'position'   => apply_filters($timetics_menu_position . 'settings', 6),
            ],
            [
                'id'         => 'my-profile',
                'title'      => esc_html__('My Profile', 'timetics'),
                'link'       => '/my-profile',
                'capability' => apply_filters($timetics_menu_permission . 'my-profile', 'edit_profile'),
                'position'   => apply_filters($timetics_menu_position . 'my-profile', 7),
            ],
            [
                'id'         => 'shortcode',
                'title'      => esc_html__('Shortcodes', 'timetics'),
                'link'       => '/shortcodes',
                'capability' => apply_filters($timetics_menu_permission . 'shortcode', 'manage_options'),
                'position'   => apply_filters($timetics_menu_position . 'shortcode', 8),
            ],
            [
                'id'         => 'onboard',
                'title'      => esc_html__('Setup Wizard', 'timetics'),
                'link'       => '/onboard',
                'capability' => apply_filters($timetics_menu_permission . 'onboard', 'manage_options'),
                'position'   => apply_filters($timetics_menu_position . 'onboard', 9),
            ],
        );

        add_menu_page(
            esc_html__('Timetics', 'timetics'),
            esc_html__('Timetics', 'timetics'),
            $capability,
            $slug,
            array($this, 'timetics_dashboard_view'),
            "data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHZpZXdCb3g9IjAgMCAxNiAxNiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGcgY2xpcC1wYXRoPSJ1cmwoI2NsaXAwXzE0MjhfMTE1OSkiPgo8cGF0aCBkPSJNOC42NDc0NSAxMy40NTEyQzUuMTc5MjQgMTMuNDUxMiAyLjQwNDY3IDEwLjY2NjcgMi43OTc3NCA3LjM4OTY0QzMuMDk4MzIgNC44MTkzNiA1LjM2NDIxIDIuNzg0NTUgOC4xMzg3OCAyLjU3MDM2QzkuOTQyMjUgMi40NDE4NSAxMS42MDcgMy4wNjMgMTIuNzYzMSA0LjEzMzk1TDE0Ljc3NDYgMi4zNzc1OUMxMy4wODY4IDAuNzkyNTg1IDEwLjcwNTMgLTAuMTI4NDMyIDguMDkyNTQgMC4wMjE1MDA3QzMuODE1MDggMC4yNTcxMSAwLjMyMzc0OSAzLjQ2OTk2IDAuMDIzMTcwNiA3LjQzMjQ4Qy0wLjMyMzY1IDEyLjEwMTggMy42NzYzNSAxNi4wMDAxIDguNjQ3NDUgMTYuMDAwMUMxMC41MjAzIDE2LjAwMDEgMTIuMjMxMyAxNS40NDMyIDEzLjY0MTcgMTQuNTIyMkMxNC4zMzUzIDE0LjA3MjQgMTQuNDA0NyAxMy4xNTE0IDEzLjgwMzUgMTIuNTk0NUwxMy43ODA0IDEyLjU3M0MxMy4yOTQ4IDEyLjEyMzIgMTIuNTU1IDEyLjEwMTggMTIuMDAwMSAxMi40NDQ1QzExLjA1MjEgMTMuMDg3MSA5Ljg5NiAxMy40NTEyIDguNjQ3NDUgMTMuNDUxMloiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik04LjY0NzQ1IDEzLjQ1MTJDNS4xNzkyNCAxMy40NTEyIDIuNDA0NjcgMTAuNjY2NyAyLjc5Nzc0IDcuMzg5NjRDMy4wOTgzMiA0LjgxOTM2IDUuMzY0MjEgMi43ODQ1NSA4LjEzODc4IDIuNTcwMzZDOS45NDIyNSAyLjQ0MTg1IDExLjYwNyAzLjA2MyAxMi43NjMxIDQuMTMzOTVMMTQuNzc0NiAyLjM3NzU5QzEzLjA4NjggMC43OTI1ODUgMTAuNzA1MyAtMC4xMjg0MzIgOC4wOTI1NCAwLjAyMTUwMDdDMy44MTUwOCAwLjI1NzExIDAuMzIzNzQ5IDMuNDY5OTYgMC4wMjMxNzA2IDcuNDMyNDhDLTAuMzIzNjUgMTIuMTAxOCAzLjY3NjM1IDE2LjAwMDEgOC42NDc0NSAxNi4wMDAxQzEwLjUyMDMgMTYuMDAwMSAxMi4yMzEzIDE1LjQ0MzIgMTMuNjQxNyAxNC41MjIyQzE0LjMzNTMgMTQuMDcyNCAxNC40MDQ3IDEzLjE1MTQgMTMuODAzNSAxMi41OTQ1TDEzLjc4MDQgMTIuNTczQzEzLjI5NDggMTIuMTIzMiAxMi41NTUgMTIuMTAxOCAxMi4wMDAxIDEyLjQ0NDVDMTEuMDUyMSAxMy4wODcxIDkuODk2IDEzLjQ1MTIgOC42NDc0NSAxMy40NTEyWiIgZmlsbD0idXJsKCNwYWludDBfbGluZWFyXzE0MjhfMTE1OSkiLz4KPHBhdGggZD0iTTE0LjQyNzggMi4wNzc1M0MxNC4yMTk3IDEuOTA2MTggMTMuOTg4NSAxLjcxMzQxIDEzLjc1NzMgMS41NjM0OEMxMy45MTkxIDEuODIwNSAxNC4wMTE2IDIuMTIwMzcgMTQuMDExNiAyLjQyMDI0QzE0LjAxMTYgMi44MjcyIDEzLjgwMzUgMy4yMzQxNiAxMy40Nzk4IDMuNTEyNjFMMTIuNzg2MiA0LjExMjM0TDguNTc4MDggNy44MTc4M0w3LjA5ODMxIDYuNDQ3MDFDNi41ODk2NCA1Ljk3NTc5IDUuNzU3MjcgNS44OTAxMiA1LjIwMjM2IDYuMzE4NUM0LjU1NDk2IDYuODExMTMgNC41MzE4NCA3LjY4OTMxIDUuMTA5ODcgOC4yMDMzN0w3LjU4Mzg2IDEwLjQ5NTJDNy44NjEzMiAxMC43NTIyIDguMjA4MTQgMTAuODU5MyA4LjU1NDk2IDEwLjg1OTNDOC45MDE3OCAxMC44NTkzIDkuMjQ4NiAxMC43NTIyIDkuNTAyOTQgMTAuNTE2NkwxNC4yNDI4IDYuMzM5OTJMMTUuNTYwNyA1LjE4MzI5QzE2LjA2OTQgNC43MzM0OSAxNi4xNjE5IDQuMDA1MjQgMTUuNzY4OCAzLjQ0ODM1QzE1LjQ2ODMgMy4wODQyMyAxNC44NDQgMi4zOTg4MiAxNC40Mjc4IDIuMDc3NTNaIiBmaWxsPSIjRTlGMkZGIi8+CjwvZz4KPGRlZnM+CjxsaW5lYXJHcmFkaWVudCBpZD0icGFpbnQwX2xpbmVhcl8xNDI4XzExNTkiIHgxPSIxMy45ODg4IiB5MT0iMC45NDY1NTYiIHgyPSI5LjI0NzY4IiB5Mj0iNS42NDI5OCIgZ3JhZGllbnRVbml0cz0idXNlclNwYWNlT25Vc2UiPgo8c3RvcCBzdG9wLWNvbG9yPSIjMEMzOTc1IiBzdG9wLW9wYWNpdHk9IjAuMzgiLz4KPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMzE2MUYxIiBzdG9wLW9wYWNpdHk9IjAiLz4KPC9saW5lYXJHcmFkaWVudD4KPGNsaXBQYXRoIGlkPSJjbGlwMF8xNDI4XzExNTkiPgo8cmVjdCB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIGZpbGw9IndoaXRlIi8+CjwvY2xpcFBhdGg+CjwvZGVmcz4KPC9zdmc+Cg==",
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
