<?php
namespace Ens\Flow;

require_once dirname( __DIR__ ) . '/src/Flow/FlowManager.php';
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

function set_transient( $key, $value, $expiration ) {
    $GLOBALS['ens_test_checkpoint'] = compact( 'key', 'value', 'expiration' );
}

class CheckpointExpiryTest extends \PHPUnit\Framework\TestCase {
    public function test_payload_survives_a_reminder_scheduled_ninety_days_ahead() {
        $resume = time() + 90 * DAY_IN_SECONDS;
        ( new FlowManager( 'tt' ) )->save_user_flow_checkpoint( $resume, 'run_123', [ 'resume_after' => $resume, 'hook_data' => [ 'booking_id' => 123 ] ] );
        $saved = $GLOBALS['ens_test_checkpoint'];
        $this->assertSame( 'flow_checkpoint_run_123_' . $resume, $saved['key'] );
        $this->assertGreaterThan( 90 * DAY_IN_SECONDS, $saved['expiration'] );
        $this->assertSame( 123, $saved['value']['hook_data']['booking_id'] );
    }

    public function test_late_cron_keeps_the_existing_minimum_lifetime() {
        ( new FlowManager( 'tt' ) )->save_user_flow_checkpoint( time() - 2 * DAY_IN_SECONDS, 'late', [ 'resume_after' => time() - 2 * DAY_IN_SECONDS ] );
        $this->assertSame( HOUR_IN_SECONDS, $GLOBALS['ens_test_checkpoint']['expiration'] );
    }
}
