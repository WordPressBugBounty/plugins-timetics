<?php
namespace Ens\Flow;

require_once dirname( __DIR__ ) . '/src/Flow/FlowManager.php';

function get_transient( $key ) {
    return $GLOBALS['ens_test_transients'][ $key ] ?? false;
}

function delete_transient( $key ) {
    unset( $GLOBALS['ens_test_transients'][ $key ] );
}

function apply_filters( $hook, $value, ...$args ) {
    $callback = $GLOBALS['ens_test_filters'][ $hook ] ?? null;

    return $callback ? $callback( $value, ...$args ) : $value;
}

class ShouldResumeFlowTest extends \PHPUnit\Framework\TestCase {
    const KEY = 'flow_checkpoint_run_1_100';

    protected function setUp(): void {
        $GLOBALS['ens_test_filters']    = [];
        $GLOBALS['ens_test_transients'] = [
            self::KEY => [
                '_flow_id'      => 'run_1',
                '_resume_time'  => 100,
                'resume_after'  => 100,
                'resume_node'   => 'node_3',
                'flow_snapshot' => [ 'nodes' => [], 'edges' => [] ],
                'hook_data'     => [ 'appointment_id' => 7 ],
                'action'        => 'appointment_scheduled',
            ],
        ];
    }

    private function resume() {
        $manager = new class( 'bt' ) extends FlowManager {
            public $ran = 0;

            public function execute_flow( $flow, $hook_data, $action, $resume_from_id = null ) {
                $this->ran++;
            }
        };
        $manager->resume_flow_callback( 'run_1', 100 );

        return $manager->ran;
    }

    public function test_runs_when_nothing_hooks_the_filter() {
        $this->assertSame( 1, $this->resume() );
    }

    public function test_host_plugin_can_skip_the_step_for_good() {
        $GLOBALS['ens_test_filters']['bt_should_resume_flow'] = function ( $resume, $hook_data, $action ) {
            return ! ( 7 === $hook_data['appointment_id'] && 'appointment_scheduled' === $action );
        };

        $this->assertSame( 0, $this->resume() );
        $this->assertArrayNotHasKey( self::KEY, $GLOBALS['ens_test_transients'] );
    }

    public function test_only_a_strict_true_resumes() {
        $GLOBALS['ens_test_filters']['bt_should_resume_flow'] = function () {
            return 1;
        };

        $this->assertSame( 0, $this->resume() );
    }
}
