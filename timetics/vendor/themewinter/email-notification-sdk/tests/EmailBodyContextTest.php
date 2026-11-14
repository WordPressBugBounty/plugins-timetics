<?php
namespace Ens\Email;

require_once dirname( __DIR__ ) . '/src/Utils/Helpers.php';
require_once dirname( __DIR__ ) . '/src/Email/EmailSender.php';

// Records what the body filter receives; every other filter passes the value through.
function apply_filters( $hook, $value, ...$args ) {
    if ( str_ends_with( $hook, 'notification_sdk_email_body' ) ) {
        $GLOBALS['ens_body_args'] = $args;
    }

    return $value;
}

function add_filter( ...$args ) {}

namespace Ens\Utils;

function get_option( $key ) {
    return [];
}

namespace Ens\Email;

function remove_filter( ...$args ) {}
function wp_mail( ...$args ) {}

class EmailBodyContextTest extends \PHPUnit\Framework\TestCase {

    public function test_the_body_filter_gets_the_subject_and_the_event() {
        $sender = new class( 'bt', 'appointment_cancelled', 'user_email', 'a@b.c', '', 'Booking cancelled', '<p>Hi</p>', [ 'id' => 7 ], 0 ) extends EmailSender {
            public function replace_placeholders( $text, $data ) {
                return $text;
            }

            protected function get_headers() {
                return [];
            }
        };
        $sender->send();

        $this->assertSame( [ 'Booking cancelled', 'appointment_cancelled', 'user_email', [ 'id' => 7 ] ], $GLOBALS['ens_body_args'] );
    }
}
