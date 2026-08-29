<?php
/**
 * Register Custom Role
 *
 * @package Timetics
 */
namespace Timetics\Base;

defined( 'ABSPATH' ) || exit;

use Timetics\Utils\Singleton;

/**
 * Class Role
 */
class Role {
    use Singleton;

    /**
     * Initialize
     *
     * @return void
     */
    public function init() {
        $this->register_role();
        $this->add_cap();
        $this->set_role();
    }

    /**
     * Register role for staff
     *
     * @return void
     */
    public function register_role() {
        $roles = $this->get_roles();

        foreach ( $roles as $role ) {
            // add_role() is a no-op if the role already exists, so on upgrade an
            // existing timetics-staff role keeps whatever caps it had before —
            // prune anything no longer in the definition below.
            $existing = get_role( $role['name'] );

            add_role(
                $role['name'],
                $role['display_name'],
                $role['capabilities']
            );

            if ( $existing ) {
                foreach ( array_diff( array_keys( $existing->capabilities ), array_keys( $role['capabilities'] ) ) as $stale_cap ) {
                    $existing->remove_cap( $stale_cap );
                }
            }
        }
    }

    /**
     * Get roles
     *
     * @return  array
     */
    public function get_roles() {
        $roles = [
            [
                'name'         => 'timetics-staff',
                'display_name' => esc_html__( 'Staff', 'timetics' ),
                'capabilities' => [
                    'read'                   => true,
                    'upload_files'           => true,
                    'read_booking'           => true,
                    'read_meeting'           => true,
                    'edit_profile'           => true,
                    'manage_timetics'        => true,

                ],
            ],
            [
                'name'         => 'timetics-customer',
                'display_name' => esc_html__( 'Timetics Customer', 'timetics' ),
                'capabilities' => [
                    'read' => true,
                ],
            ],
        ];

        return apply_filters( 'timetics_staff_roles', $roles );
    }

    /**
     * Add capabilites to a role
     *
     * @return void
     */
    public function add_cap() {
        global $wp_roles;

        $capabilities = $this->get_capabilities();
        $wp_roles->add_cap( 'administrator', 'manage_timetics' );

        foreach ( $capabilities as $cap_group ) {
            foreach ( $cap_group as $cap ) {
                $wp_roles->add_cap( 'administrator', $cap );
            }
        }
    }

    /**
     * Get capabilities
     *
     * @return  array
     */
    public function get_capabilities() {
        $capability_types = ['meeting', 'booking', 'staff'];

        $capabilites = [];

        foreach ( $capability_types as $capability_type ) {
            $capabilites[$capability_type] = [
                // Post type.
                "edit_{$capability_type}",
                "read_{$capability_type}",
                "delete_{$capability_type}",
                "edit_{$capability_type}s",
                "edit_others_{$capability_type}s",
                "publish_{$capability_type}s",
                "read_private_{$capability_type}s",
                "delete_{$capability_type}s",
                "delete_private_{$capability_type}s",
                "delete_published_{$capability_type}s",
                "delete_others_{$capability_type}s",
                "edit_private_{$capability_type}s",
            ];
        }

        return $capabilites;
    }

    /**
     * Set user role
     *
     * @return  void
     */
    public function set_role() {
        $users = get_users( ['role' => 'administrator'] );

        foreach( $users as $user ) {
            $user->add_role('timetics-staff');
            $user->add_role('timetics-customer');
        }

		$users = get_users( ['role' => 'timetics-staff'] );

        foreach ( $users as $user ) {
            $user->add_role('timetics-customer');
        }
    }
}
