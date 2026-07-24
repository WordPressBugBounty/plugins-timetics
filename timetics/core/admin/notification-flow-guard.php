<?php
/**
 * Notification_Flow_Guard — keeps delayed automation flows in sync with the
 * booking they were triggered for.
 *
 * The email-notification-sdk implements "wait N hours before the meeting" by
 * scheduling a `tt_resume_flow_after_delay` cron event and freezing the whole
 * trigger payload into a `flow_checkpoint_{flow_id}_{resume_time}` transient.
 * When the event fires, the SDK replays that frozen payload without ever
 * looking at the booking again, so a booking that was cancelled, deleted or
 * moved after the flow started still gets its reminder — with the old details.
 *
 * This class closes that gap from the plugin side:
 *
 * 1. Proactively, when a booking is cancelled/deleted the pending resume is
 *    unscheduled and its checkpoint removed; when a booking is rescheduled the
 *    pending resume is re-scheduled against the new meeting time with a
 *    refreshed payload, so the reminder survives but quotes the right time.
 * 2. Defensively, on `tt_resume_flow_after_delay` at priority 1 — before the
 *    SDK's own priority 10 callback — the checkpoint is re-validated against
 *    the live booking. Deleting the transient there is enough to stop the SDK,
 *    because `resume_flow_callback()` bails when the checkpoint is missing.
 *
 * @package Timetics
 */

namespace Timetics\Core\Admin;

use Timetics\Core\Bookings\Booking;
use Timetics\Core\Bookings\Hooks as Booking_Hooks;
use Timetics\Utils\Singleton;

defined( 'ABSPATH' ) || exit;

class Notification_Flow_Guard {

    use Singleton;

    /**
     * Cron hook the SDK schedules delayed flow resumes on.
     *
     * `tt` is the `general_prefix` Timetics registers the SDK with.
     */
    const RESUME_HOOK = 'tt_resume_flow_after_delay';

    /**
     * Booking statuses that must never receive a delayed notification.
     *
     * @var string[]
     */
    const DEAD_STATUSES = array( 'cancel', 'cancelled', 'failed', 'trash' );

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init() {
        add_action( self::RESUME_HOOK, array( $this, 'validate_pending_flow' ), 1, 2 );

        // Catching the status transition instead of each cancel call site means
        // every route into a dead status is covered — REST, admin, WooCommerce
        // order sync, failed payments — with one registration.
        add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
        add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 1 );
        add_action( 'wp_trash_post', array( $this, 'on_delete' ), 10, 1 );
    }

    /**
     * Drop pending notifications when a booking moves into a dead status.
     *
     * @param  string   $new_status
     * @param  string   $old_status
     * @param  \WP_Post $post
     * @return void
     */
    public function on_status_change( $new_status, $old_status, $post ) {
        if ( ! $post instanceof \WP_Post || 'timetics-booking' !== $post->post_type ) {
            return;
        }

        if ( $new_status === $old_status || ! in_array( $new_status, self::DEAD_STATUSES, true ) ) {
            return;
        }

        self::purge( $post->ID );
    }

    /**
     * Drop pending notifications when a booking is trashed or deleted.
     *
     * @param  int $post_id
     * @return void
     */
    public function on_delete( $post_id ) {
        if ( 'timetics-booking' !== get_post_type( $post_id ) ) {
            return;
        }

        self::purge( $post_id );
    }

    /**
     * Remove both notification mechanisms queued for a booking: the SDK's
     * delayed flow resumes and the built-in reminder cron.
     *
     * @param  int $booking_id
     * @return void
     */
    private static function purge( $booking_id ) {
        self::clear_pending_flows( $booking_id );
        Booking_Hooks::clear_reminders( $booking_id );
    }

    /**
     * Re-validate a checkpoint against the live booking just before the SDK
     * replays it. Removing the transient makes the SDK's resume a no-op.
     *
     * @param  string $flow_id
     * @param  int    $resume_time
     * @return void
     */
    public function validate_pending_flow( $flow_id, $resume_time ) {
        $key        = self::checkpoint_key( $flow_id, $resume_time );
        $checkpoint = get_transient( $key );

        if ( ! is_array( $checkpoint ) || empty( $checkpoint['hook_data'] ) ) {
            return;
        }

        $booking_id = self::checkpoint_booking_id( $checkpoint );

        // Checkpoints saved before this fix carry no booking id. Nothing can be
        // re-checked, so leave the SDK's original behaviour untouched.
        if ( ! $booking_id ) {
            return;
        }

        if ( ! self::is_deliverable( $booking_id, $checkpoint['hook_data'] ) ) {
            delete_transient( $key );
        }
    }

    /**
     * Drop every pending delayed flow belonging to a booking.
     *
     * Used when the booking is cancelled or deleted — the reminder is no longer
     * wanted at any time.
     *
     * @param  int $booking_id
     * @return int Number of pending resumes cleared.
     */
    public static function clear_pending_flows( $booking_id ) {
        $cleared = 0;

        foreach ( self::find_pending_flows( $booking_id ) as $pending ) {
            self::unschedule( $pending['args'] );
            delete_transient( self::checkpoint_key( $pending['flow_id'], $pending['resume_time'] ) );
            $cleared++;
        }

        return $cleared;
    }

    /**
     * Move every pending delayed flow of a booking to match its new meeting
     * time, refreshing the frozen payload at the same time.
     *
     * The offset between the original meeting timestamp and the original resume
     * time is preserved, so a "24 hours before" reminder stays 24 hours before
     * the new date. A resume that would land in the past is dropped rather than
     * fired immediately.
     *
     * @param  int   $booking_id
     * @param  array $fresh_hook_data New payload from Notification::get_hook_data().
     * @return int   Number of pending resumes re-scheduled.
     */
    public static function reschedule_pending_flows( $booking_id, $fresh_hook_data ) {
        if ( empty( $fresh_hook_data['meeting_date_timestamp'] ) ) {
            return 0;
        }

        $new_meeting_ts = (int) $fresh_hook_data['meeting_date_timestamp'];
        $moved          = 0;

        foreach ( self::find_pending_flows( $booking_id ) as $pending ) {
            $checkpoint = $pending['checkpoint'];
            $old_hook   = $checkpoint['hook_data'];
            $old_meet   = isset( $old_hook['meeting_date_timestamp'] ) ? (int) $old_hook['meeting_date_timestamp'] : 0;

            // Always drop the stale event + checkpoint first.
            self::unschedule( $pending['args'] );
            delete_transient( self::checkpoint_key( $pending['flow_id'], $pending['resume_time'] ) );

            if ( ! $old_meet ) {
                continue;
            }

            $offset          = (int) $pending['resume_time'] - $old_meet;
            $new_resume_time = $new_meeting_ts + $offset;

            if ( $new_resume_time <= time() ) {
                continue;
            }

            $new_flow_id = uniqid( 'flow_', true );

            $checkpoint['hook_data']    = array_merge( $old_hook, $fresh_hook_data );
            $checkpoint['resume_after'] = $new_resume_time;
            $checkpoint['_flow_id']     = $new_flow_id;
            $checkpoint['_resume_time'] = $new_resume_time;
            $checkpoint['_saved_at']    = time();

            self::save_checkpoint( $new_flow_id, $new_resume_time, $checkpoint );

            wp_schedule_single_event(
                $new_resume_time,
                self::RESUME_HOOK,
                array(
                    'flow_id'     => $new_flow_id,
                    'resume_time' => $new_resume_time,
                )
            );

            $moved++;
        }

        return $moved;
    }

    /**
     * Collect the scheduled resume events that belong to a booking.
     *
     * The SDK does not index its cron events by booking, so the cron array is
     * walked and each checkpoint inspected. Cancels and reschedules are rare
     * enough that the cost is irrelevant.
     *
     * @param  int $booking_id
     * @return array<int, array{flow_id:string,resume_time:int,args:array,checkpoint:array}>
     */
    private static function find_pending_flows( $booking_id ) {
        $booking_id = (int) $booking_id;
        $found      = array();

        if ( ! $booking_id ) {
            return $found;
        }

        $cron = _get_cron_array();

        if ( ! is_array( $cron ) ) {
            return $found;
        }

        foreach ( $cron as $events ) {
            if ( ! is_array( $events ) || empty( $events[ self::RESUME_HOOK ] ) ) {
                continue;
            }

            foreach ( $events[ self::RESUME_HOOK ] as $event ) {
                $args = isset( $event['args'] ) ? $event['args'] : array();

                if ( ! isset( $args['flow_id'], $args['resume_time'] ) ) {
                    continue;
                }

                $checkpoint = get_transient( self::checkpoint_key( $args['flow_id'], $args['resume_time'] ) );

                if ( ! is_array( $checkpoint ) || empty( $checkpoint['hook_data'] ) ) {
                    continue;
                }

                if ( self::checkpoint_booking_id( $checkpoint ) !== $booking_id ) {
                    continue;
                }

                $found[] = array(
                    'flow_id'     => $args['flow_id'],
                    'resume_time' => (int) $args['resume_time'],
                    'args'        => $args,
                    'checkpoint'  => $checkpoint,
                );
            }
        }

        return $found;
    }

    /**
     * Whether a booking should still receive a delayed notification.
     *
     * @param  int   $booking_id
     * @param  array $hook_data Frozen payload from the checkpoint.
     * @return bool
     */
    private static function is_deliverable( $booking_id, $hook_data ) {
        $post = get_post( $booking_id );

        if ( ! $post || 'timetics-booking' !== $post->post_type ) {
            return false;
        }

        if ( in_array( $post->post_status, self::DEAD_STATUSES, true ) ) {
            return false;
        }

        // A payload frozen before a reschedule quotes the old date/time. If the
        // proactive re-schedule did not run for some reason, suppress rather
        // than send wrong details.
        if ( ! empty( $hook_data['meeting_date_timestamp'] ) ) {
            $booking = new Booking( $booking_id );
            $current = Notification::get_booking_timestamp( $booking );

            if ( $current && (int) $current !== (int) $hook_data['meeting_date_timestamp'] ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read the booking id out of a checkpoint payload.
     *
     * `post_id` is the key the SDK itself looks for; `booking_id` is kept as an
     * explicit alias for readability in flow templates.
     *
     * @param  array $checkpoint
     * @return int
     */
    private static function checkpoint_booking_id( $checkpoint ) {
        $hook_data = isset( $checkpoint['hook_data'] ) ? $checkpoint['hook_data'] : array();

        if ( ! empty( $hook_data['post_id'] ) ) {
            return (int) $hook_data['post_id'];
        }

        if ( ! empty( $hook_data['booking_id'] ) ) {
            return (int) $hook_data['booking_id'];
        }

        return 0;
    }

    /**
     * Build the SDK's checkpoint transient key.
     *
     * @param  string $flow_id
     * @param  int    $resume_time
     * @return string
     */
    private static function checkpoint_key( $flow_id, $resume_time ) {
        return sprintf( 'flow_checkpoint_%s_%s', $flow_id, $resume_time );
    }

    /**
     * Persist a checkpoint using the SDK's expiry rules.
     *
     * @param  string $flow_id
     * @param  int    $resume_time
     * @param  array  $checkpoint
     * @return void
     */
    private static function save_checkpoint( $flow_id, $resume_time, $checkpoint ) {
        $expiration = max(
            HOUR_IN_SECONDS,
            min( $resume_time - time() + DAY_IN_SECONDS, 30 * DAY_IN_SECONDS )
        );

        set_transient( self::checkpoint_key( $flow_id, $resume_time ), $checkpoint, $expiration );
    }

    /**
     * Remove a scheduled resume event.
     *
     * WP hashes cron args, so the array must be passed through exactly as the
     * SDK registered it.
     *
     * @param  array $args
     * @return void
     */
    private static function unschedule( $args ) {
        $timestamp = wp_next_scheduled( self::RESUME_HOOK, $args );

        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::RESUME_HOOK, $args );
            $next = wp_next_scheduled( self::RESUME_HOOK, $args );

            if ( $next === $timestamp ) {
                break;
            }

            $timestamp = $next;
        }
    }
}
