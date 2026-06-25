<?php
/**
 * Seeds the 8 default notification flows on first activation.
 *
 * @package Timetics
 */

namespace Timetics\Core\Admin;

use Ens\Flow\Flow;

defined( 'ABSPATH' ) || exit;

class Notification_Seeder {

    const SEEDED_OPTION  = 'timetics_default_flows_seeded';
    const SEEDED_VERSION = '2.0';

    /**
     * Seed the 8 default flows as drafts.
     *
     * The legacy email system in Settings remains the active sender; these
     * flows ship as drafts (examples) so they never fire until a user
     * explicitly publishes one.
     */
    public static function maybe_seed() {
        self::migrate_bad_flows();

        if ( get_option( self::SEEDED_OPTION ) === self::SEEDED_VERSION ) {
            return;
        }

        $existing = self::existing_flow_titles();

        // Demote previously published default seeds so they stop firing.
        self::draft_published_seeds();

        foreach ( self::get_default_flows() as $flow_def ) {
            // Skip if a flow with this name already exists (avoids duplicates
            // on upgrade); user-renamed flows are left untouched.
            if ( in_array( $flow_def['name'], $existing, true ) ) {
                continue;
            }
            self::create_flow( $flow_def );
        }

        update_option( self::SEEDED_OPTION, self::SEEDED_VERSION, false );
    }

    /**
     * Titles of all tt-flow posts currently in the DB (any status).
     *
     * @return string[]
     */
    private static function existing_flow_titles() {
        $posts = get_posts( array(
            'post_type'      => 'tt-flow',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ) );

        return wp_list_pluck( $posts, 'post_title' );
    }

    /**
     * Demote any published flow whose title matches a default seed name back
     * to draft, so the legacy Settings emails remain the sole sender.
     */
    private static function draft_published_seeds() {
        $seed_names = wp_list_pluck( self::get_default_flows(), 'name' );

        $posts = get_posts( array(
            'post_type'      => 'tt-flow',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ) );

        foreach ( $posts as $post_id ) {
            if ( in_array( get_the_title( $post_id ), $seed_names, true ) ) {
                wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
            }
        }
    }

    /**
     * Delete any tt-flow posts whose nodes are missing the 'type' field —
     * those were created by the v1.0 seeder with the wrong format.
     * User-created flows always have 'type' on every node (set by the UI).
     * Also resets the seeded flag so maybe_seed() re-creates correct flows.
     */
    private static function migrate_bad_flows() {
        if ( get_option( self::SEEDED_OPTION ) === self::SEEDED_VERSION ) {
            return;
        }

        $posts = get_posts( array(
            'post_type'      => 'tt-flow',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ) );

        if ( empty( $posts ) ) {
            return;
        }

        $meta_key = '_tt_notification_flow_flow_config';

        foreach ( $posts as $post_id ) {
            $config = get_post_meta( $post_id, $meta_key, true );

            if ( empty( $config['nodes'] ) || ! is_array( $config['nodes'] ) ) {
                continue;
            }

            foreach ( $config['nodes'] as $node ) {
                if ( ! isset( $node['type'] ) ) {
                    wp_delete_post( $post_id, true );
                    break;
                }
            }
        }
    }

    /**
     * Force re-seed (e.g., for testing). Clears flag then re-runs.
     */
    public static function reseed() {
        delete_option( self::SEEDED_OPTION );
        self::maybe_seed();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function create_flow( array $def ) {
        $flow = new Flow( 'tt' );
        $flow->set_props( array(
            'name'        => $def['name'],
            'trigger'     => $def['trigger'],
            'flow_config' => $def['flow_config'],
            'status'      => 'draft',
        ) );
        $flow->save();
    }

    /** Edge with correct UI format. */
    private static function edge( string $from, string $to ) {
        return array(
            'id'        => 'edge_' . $from . '-' . $to,
            'type'      => 'smoothstep',
            'markerEnd' => array( 'type' => 'arrowclosed' ),
            'source'    => $from,
            'target'    => $to,
            'data'      => array( 'animated' => false ),
        );
    }

    /** Trigger node. */
    private static function trigger_node( string $trigger_value, string $trigger_label ) {
        return array(
            'id'       => 'node_1',
            'type'     => 'trigger',
            'name'     => 'trigger',
            'position' => array( 'x' => 275, 'y' => 70 ),
            'data'     => array(
                'label'        => 'trigger: ' . $trigger_value,
                'subtitle'     => 'On "' . $trigger_label . '" event fires',
                'triggerValue' => $trigger_value,
            ),
        );
    }

    /** End node. */
    private static function end_node( float $y = 600.0 ) {
        return array(
            'id'       => 'end_1',
            'type'     => 'end',
            'name'     => 'end',
            'position' => array( 'x' => 275, 'y' => $y ),
            'data'     => array(
                'label'    => 'end_flow',
                'subtitle' => 'Automation stops here',
            ),
        );
    }

    /** Email action node. */
    private static function email_node( string $node_id, string $receiver_type, string $receiver_label, string $subject, string $body, float $y = 420.0 ) {
        return array(
            'id'       => $node_id,
            'type'     => 'action',
            'name'     => 'email',
            'position' => array( 'x' => 275, 'y' => $y ),
            'data'     => array(
                'actionType'   => 'send_email',
                'label'        => 'send_email',
                'subtitle'     => 'To: ' . $receiver_label,
                'receiverType' => $receiver_type,
                'from'         => '',
                'subject'      => $subject,
                'body'         => $body,
            ),
        );
    }

    /** Delay action node. */
    private static function delay_node( string $node_id, int $delay, string $delay_unit, string $delay_condition, float $y = 245.0 ) {
        $subtitle = 'Wait for ' . $delay . ' ' . $delay_unit;
        if ( $delay_condition ) {
            $subtitle .= ' before ' . str_replace( '_', ' ', str_replace( 'before_', '', $delay_condition ) );
        }

        return array(
            'id'       => $node_id,
            'type'     => 'action',
            'name'     => 'delay',
            'position' => array( 'x' => 275, 'y' => $y ),
            'data'     => array(
                'actionType'     => 'add_delay',
                'label'          => 'add_delay',
                'subtitle'       => $subtitle,
                'delay'          => $delay,
                'delayUnit'      => $delay_unit,
                'delayCondition' => $delay_condition,
            ),
        );
    }

    /**
     * trigger → email → end
     */
    private static function simple_flow( string $trigger_value, string $trigger_label, string $receiver_type, string $receiver_label, string $subject, string $body ) {
        return array(
            'nodes' => array(
                self::trigger_node( $trigger_value, $trigger_label ),
                self::email_node( 'node_2', $receiver_type, $receiver_label, $subject, $body, 280.0 ),
                self::end_node( 490.0 ),
            ),
            'edges' => array(
                self::edge( 'node_1', 'node_2' ),
                self::edge( 'node_2', 'end_1' ),
            ),
        );
    }

    /**
     * trigger → delay → email → end
     */
    private static function reminder_flow( string $trigger_value, string $trigger_label, string $receiver_type, string $receiver_label, string $subject, string $body ) {
        return array(
            'nodes' => array(
                self::trigger_node( $trigger_value, $trigger_label ),
                self::delay_node( 'node_2', 24, 'hours', 'before_meeting_date', 245.0 ),
                self::email_node( 'node_3', $receiver_type, $receiver_label, $subject, $body, 420.0 ),
                self::end_node( 600.0 ),
            ),
            'edges' => array(
                self::edge( 'node_1', 'node_2' ),
                self::edge( 'node_2', 'node_3' ),
                self::edge( 'node_3', 'end_1' ),
            ),
        );
    }

    /**
     * Definitions for all 8 default flows.
     */
    private static function get_default_flows() {
        $booking_tags = implode( '', array(
            '<table style="border-collapse:collapse;width:100%;margin:20px 0;">',
            '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;width:40%;">Meeting</td><td style="padding:8px 0;color:#556880;">{%meeting_title%}</td></tr>',
            '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Date</td><td style="padding:8px 0;color:#556880;">{%meeting_date%}</td></tr>',
            '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Time</td><td style="padding:8px 0;color:#556880;">{%meeting_time%}</td></tr>',
            '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Duration</td><td style="padding:8px 0;color:#556880;">{%meeting_duration%}</td></tr>',
            '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Location</td><td style="padding:8px 0;color:#556880;">{%meeting_location%}</td></tr>',
        ) );

        return array(

            // ── Booking Confirmed ──────────────────────────────────────────
            array(
                'name'        => 'Booking Confirmed – Customer',
                'trigger'     => 'booking_created',
                'flow_config' => self::simple_flow(
                    'booking_created',
                    'After Booking Confirmation',
                    'customer_email',
                    'Customer Email',
                    'Booking Confirmed: {%meeting_title%}',
                    '<p>Hi {%customer_name%},</p><p>Your booking has been confirmed.</p>'
                    . $booking_tags
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Host</td><td style="padding:8px 0;color:#556880;">{%host_name%}</td></tr>'
                    . '</table><p>Thank you!</p>'
                ),
            ),

            array(
                'name'        => 'Booking Confirmed – Host',
                'trigger'     => 'booking_created',
                'flow_config' => self::simple_flow(
                    'booking_created',
                    'After Booking Confirmation',
                    'host_email',
                    'Host Email',
                    'New Booking: {%meeting_title%}',
                    '<p>Hi {%host_name%},</p><p>A new booking has been made.</p>'
                    . $booking_tags
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Customer</td><td style="padding:8px 0;color:#556880;">{%customer_name%} ({%customer_email%})</td></tr>'
                    . '</table><p>Thank you!</p>'
                ),
            ),

            // ── Booking Canceled ───────────────────────────────────────────
            array(
                'name'        => 'Booking Canceled – Customer',
                'trigger'     => 'booking_canceled',
                'flow_config' => self::simple_flow(
                    'booking_canceled',
                    'After Booking Cancellation',
                    'customer_email',
                    'Customer Email',
                    'Booking Canceled: {%meeting_title%}',
                    '<p>Hi {%customer_name%},</p><p>Your booking has been canceled.</p>'
                    . '<table style="border-collapse:collapse;width:100%;margin:20px 0;">'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;width:40%;">Meeting</td><td style="padding:8px 0;color:#556880;">{%meeting_title%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Date</td><td style="padding:8px 0;color:#556880;">{%meeting_date%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Time</td><td style="padding:8px 0;color:#556880;">{%meeting_time%}</td></tr>'
                    . '</table><p>If you have questions, please contact us.</p><p>Thank you!</p>'
                ),
            ),

            array(
                'name'        => 'Booking Canceled – Host',
                'trigger'     => 'booking_canceled',
                'flow_config' => self::simple_flow(
                    'booking_canceled',
                    'After Booking Cancellation',
                    'host_email',
                    'Host Email',
                    'Booking Canceled: {%meeting_title%}',
                    '<p>Hi {%host_name%},</p><p>A booking has been canceled.</p>'
                    . '<table style="border-collapse:collapse;width:100%;margin:20px 0;">'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;width:40%;">Meeting</td><td style="padding:8px 0;color:#556880;">{%meeting_title%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Date</td><td style="padding:8px 0;color:#556880;">{%meeting_date%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Time</td><td style="padding:8px 0;color:#556880;">{%meeting_time%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Customer</td><td style="padding:8px 0;color:#556880;">{%customer_name%} ({%customer_email%})</td></tr>'
                    . '</table><p>Thank you!</p>'
                ),
            ),

            // ── Booking Rescheduled ────────────────────────────────────────
            array(
                'name'        => 'Booking Rescheduled – Customer',
                'trigger'     => 'booking_rescheduled',
                'flow_config' => self::simple_flow(
                    'booking_rescheduled',
                    'After Booking Rescheduled',
                    'customer_email',
                    'Customer Email',
                    'Booking Rescheduled: {%meeting_title%}',
                    '<p>Hi {%customer_name%},</p><p>Your booking has been rescheduled.</p>'
                    . '<table style="border-collapse:collapse;width:100%;margin:20px 0;">'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;width:40%;">Meeting</td><td style="padding:8px 0;color:#556880;">{%meeting_title%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">New Date</td><td style="padding:8px 0;color:#556880;">{%meeting_date%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">New Time</td><td style="padding:8px 0;color:#556880;">{%meeting_time%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Location</td><td style="padding:8px 0;color:#556880;">{%meeting_location%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Host</td><td style="padding:8px 0;color:#556880;">{%host_name%}</td></tr>'
                    . '</table><p>Thank you!</p>'
                ),
            ),

            array(
                'name'        => 'Booking Rescheduled – Host',
                'trigger'     => 'booking_rescheduled',
                'flow_config' => self::simple_flow(
                    'booking_rescheduled',
                    'After Booking Rescheduled',
                    'host_email',
                    'Host Email',
                    'Booking Rescheduled: {%meeting_title%}',
                    '<p>Hi {%host_name%},</p><p>A booking has been rescheduled.</p>'
                    . '<table style="border-collapse:collapse;width:100%;margin:20px 0;">'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;width:40%;">Meeting</td><td style="padding:8px 0;color:#556880;">{%meeting_title%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">New Date</td><td style="padding:8px 0;color:#556880;">{%meeting_date%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">New Time</td><td style="padding:8px 0;color:#556880;">{%meeting_time%}</td></tr>'
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Customer</td><td style="padding:8px 0;color:#556880;">{%customer_name%} ({%customer_email%})</td></tr>'
                    . '</table><p>Thank you!</p>'
                ),
            ),

            // ── Booking Reminder (24 h before) ─────────────────────────────
            array(
                'name'        => 'Booking Reminder – Customer',
                'trigger'     => 'booking_created',
                'flow_config' => self::reminder_flow(
                    'booking_created',
                    'After Booking Confirmation',
                    'customer_email',
                    'Customer Email',
                    'Reminder: {%meeting_title%} is coming up',
                    '<p>Hi {%customer_name%},</p><p>This is a reminder that your meeting is coming up soon.</p>'
                    . $booking_tags
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Host</td><td style="padding:8px 0;color:#556880;">{%host_name%}</td></tr>'
                    . '</table><p>We look forward to seeing you!</p><p>Thank you!</p>'
                ),
            ),

            array(
                'name'        => 'Booking Reminder – Host',
                'trigger'     => 'booking_created',
                'flow_config' => self::reminder_flow(
                    'booking_created',
                    'After Booking Confirmation',
                    'host_email',
                    'Host Email',
                    'Reminder: {%meeting_title%} is coming up',
                    '<p>Hi {%host_name%},</p><p>This is a reminder that a meeting is coming up soon.</p>'
                    . $booking_tags
                    . '<tr><td style="padding:8px 0;font-weight:600;color:#0C274A;">Customer</td><td style="padding:8px 0;color:#556880;">{%customer_name%} ({%customer_email%})</td></tr>'
                    . '</table><p>Thank you!</p>'
                ),
            ),

        );
    }
}
