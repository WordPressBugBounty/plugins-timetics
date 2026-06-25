<?php
/**
 * Fires when the user deletes the plugin from the Plugins screen.
 * Clears onboarding flags so a reinstall re-triggers the onboarding flow.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'timetics_onboard_setup' );
delete_option( 'timetics_onboard_settings' );
delete_option( 'timetics_default_flows_seeded' );
