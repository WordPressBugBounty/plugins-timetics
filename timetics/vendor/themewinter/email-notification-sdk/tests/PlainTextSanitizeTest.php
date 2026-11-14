<?php
namespace Ens\Utils;

require_once dirname( __DIR__ ) . '/src/Utils/Helpers.php';

// Like WordPress: kses turns a lone < or > into an entity.
function wp_kses_post( $text ) {
    return 'kses:' . str_replace( [ '<script>', '</script>' ], '', $text );
}

function is_email( $text ) {
    return false;
}

class PlainTextSanitizeTest extends \PHPUnit\Framework\TestCase {

    public function test_a_condition_label_keeps_its_symbols() {
        $saved = Helpers::ens_sanitize_recursive(
            [ 'nodes' => [ [ 'data' => [ 'subtitle' => 'Sessions in series > 1', 'other' => 'Count < 3 & more', 'value' => 1 ] ] ] ]
        );

        $this->assertSame( 'Sessions in series > 1', $saved['nodes'][0]['data']['subtitle'] );
        $this->assertSame( 'Count < 3 & more', $saved['nodes'][0]['data']['other'] );
        $this->assertSame( 1, $saved['nodes'][0]['data']['value'] );
    }

    public function test_text_saved_with_entities_reads_right_again() {
        $this->assertSame( 'Sessions in series > 1', Helpers::ens_sanitize_recursive( 'Sessions in series &gt; 1' ) );
    }

    public function test_html_still_goes_through_kses() {
        $this->assertSame( 'kses:<p>Hi</p>', Helpers::ens_sanitize_recursive( '<p>Hi</p><script></script>' ) );
        // Encoded tags do not slip past as plain text.
        $this->assertSame( 'x', Helpers::ens_sanitize_recursive( '&lt;b&gt;x&lt;/b&gt;' ) );
    }
}
