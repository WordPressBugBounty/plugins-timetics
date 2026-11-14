<?php
namespace Ens\Flow;

require_once dirname( __DIR__ ) . '/src/Flow/FlowManager.php';

class ConditionBranchTest extends \PHPUnit\Framework\TestCase {

    private function edge( $target, $extra ) {
        return [ 'source' => 'check', 'target' => $target ] + $extra;
    }

    public function test_a_condition_built_in_the_editor_takes_the_right_branch() {
        $manager = new FlowManager( 'tt' );

        // As the editor saves it, with the labels translated.
        $edges = [
            $this->edge( 'yes', [ 'label' => 'Sí', 'sourceHandle' => 'true', 'data' => [ 'condition' => 'true' ] ] ),
            $this->edge( 'no', [ 'label' => 'Falso', 'sourceHandle' => 'false', 'data' => [ 'condition' => 'false' ] ] ),
        ];

        $this->assertSame( 'yes', $manager->get_next_node_id( $edges, 'check', 'True' ) );
        $this->assertSame( 'no', $manager->get_next_node_id( $edges, 'check', 'false' ) );
    }

    public function test_older_flows_still_follow_their_labels() {
        $manager = new FlowManager( 'tt' );
        $edges   = [
            $this->edge( 'no', [ 'label' => 'false', 'data' => (object) [] ] ),
            $this->edge( 'yes', [ 'label' => 'True' ] ),
        ];

        $this->assertSame( 'yes', $manager->get_next_node_id( $edges, 'check', 'True' ) );
        $this->assertSame( 'no', $manager->get_next_node_id( $edges, 'check', 'false' ) );
    }

    public function test_the_editor_operator_names_compare() {
        $manager = new FlowManager( 'tt' );

        $this->assertTrue( $manager->compare_values( 3, 'equal', 3 ) );
        $this->assertTrue( $manager->compare_values( 3, 'greater_then', 2 ) );
        $this->assertFalse( $manager->compare_values( 2, 'greater_then', 2 ) );
        $this->assertTrue( $manager->compare_values( 1, 'less_then', 2 ) );
        $this->assertTrue( $manager->compare_values( 3, '=', 3 ) );
    }
}
