<?php
namespace Ens\Flow;

require_once dirname( __DIR__ ) . '/src/Flow/FlowManager.php';

// Like WordPress: without numberposts, get_posts() returns 5 posts.
function get_posts( $args ) {
    $limit = $args['numberposts'] ?? 5;

    return -1 === $limit ? $GLOBALS['ens_test_flows'] : array_slice( $GLOBALS['ens_test_flows'], 0, $limit );
}

function get_post_meta( $post_id, $key, $single ) {
    return [ 'nodes' => [], 'edges' => [] ];
}

class RunEveryFlowTest extends \PHPUnit\Framework\TestCase {

    public function test_every_published_flow_for_the_event_runs() {
        $GLOBALS['ens_test_flows'] = array_map(
            function ( $id ) {
                return (object) [ 'ID' => $id ];
            },
            range( 1, 7 )
        );

        $manager = new class( 'tt' ) extends FlowManager {
            public $ran = 0;

            public function execute_flow( $flow, $hook_data, $action, $resume_from_id = null ) {
                $this->ran++;
            }
        };
        $manager->handle( 'booking_created', [] );

        $this->assertSame( 7, $manager->ran );
    }
}
