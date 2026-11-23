<?php
/**
 * MCP server
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the Timetics MCP URL when the MCP Adapter plugin is active.
 *
 * Holds no business rule: the WordPress AI plugin can expose the same abilities
 * through another server, so every check lives in the ability or the reader.
 */
class Mcp_Server {

    /**
     * Whether the adapter took the server in this request.
     *
     * @var bool
     */
    private static $running = false;

    /**
     * Whether the MCP URL answers. A loaded adapter class alone does not say it: another
     * plugin's copy may never start, or refuse the server.
     *
     * @return  bool
     */
    public static function is_running(): bool {
        return self::$running;
    }

    /**
     * Create the server. A failure stays here, so booking keeps working whichever
     * adapter copy another plugin loaded.
     *
     * @param   object  $adapter  The adapter instance.
     *
     * @return  void
     */
    public static function create( $adapter ) {
        self::$running = false;

        try {
            $result = $adapter->create_server(
                'timetics',
                'timetics/v1',
                'mcp',
                __( 'WPTimetics', 'timetics' ),
                __( 'View and manage WPTimetics bookings, meetings, staff and customers.', 'timetics' ),
                \Timetics::get_version(),
                array( 'WP\\MCP\\Transport\\HttpTransport' ),
                null,
                null,
                Abilities::names(),
                array(),
                array(),
                // A plain bool: an older adapter copy treats a WP_Error as "fall back to the read capability".
                function () {
                    return Mcp_Access::can_use_tools();
                }
            );

            if ( is_wp_error( $result ) ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Site owners need the reason.
                error_log( 'WPTimetics MCP server not created: ' . $result->get_error_message() );
                return;
            }

            self::$running = true;
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Site owners need the reason.
            error_log( 'WPTimetics MCP server not created: ' . $e->getMessage() );
        }
    }
}
