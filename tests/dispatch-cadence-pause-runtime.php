<?php
/**
 * A paused Dispatch does not file cadence drafts, and a publish result is refused.
 *
 * Run: php tests/dispatch-cadence-pause-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );

class WP_Error {
	public $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; return true; }
function update_post_meta() { return true; }

class Lunara_Dispatch_Control_Plane_Client {
	public static function enabled() { return ! empty( $GLOBALS['dispatch_enabled'] ); }
	public static function ingest_foundation_draft() {
		return array( 'post_status' => 'publish', 'post_id' => 99, 'created' => true );
	}
}

$GLOBALS['options'] = array();
$GLOBALS['dispatch_enabled'] = false;
$GLOBALS['checks'] = 0;
function pause_check( $ok, $message ) {
	$GLOBALS['checks']++;
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require $root . '/includes/class-cadence.php';

$now = new DateTimeImmutable( '2026-11-21 20:05:00', new DateTimeZone( 'America/Chicago' ) );
$topic = array(
	'id' => 'paused-item',
	'pillar' => 'news',
	'title' => 'Paused Film Is the Piece I Cannot Drop',
	'film' => 'Paused Film',
	'facts' => array(
		'The trailer has been on my mind since this morning and I am not done with it.',
		'The choice on screen is specific enough to argue about instead of recap.',
	),
	'stake' => 'I want this one on the desk because the taste is the point.',
	'source' => 'Empire',
);
$paused = Lunara_Dispatch_Cadence::fill_due_slots( array(
	'now' => $now,
	'filed' => 0,
	'candidates' => array( $topic ),
	'insert' => static function () {
		pause_check( false, 'paused automation must not store a draft' );
	},
) );
pause_check( 0 === $paused['created'] && 'draft' === $paused['post_status'], 'Dispatch automation off files nothing and reports drafts, not publication' );

$GLOBALS['dispatch_enabled'] = true;
$refused = Lunara_Dispatch_Cadence::fill_due_slots( array(
	'now' => $now,
	'filed' => 0,
	'candidates' => array( $topic ),
) );
pause_check( 0 === $refused['created'] && ! empty( $refused['errors'] ), 'a Foundation result that comes back published is refused' );

fwrite( STDOUT, "Dispatch cadence pause runtime passed: {$GLOBALS['checks']} checks.\n" );
