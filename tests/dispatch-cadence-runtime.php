<?php
/**
 * Cadence behavior: topic selection, local composition, and held drafts.
 *
 * Run: php tests/dispatch-cadence-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );

class WP_Error {
	public $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; return true; }
function update_post_meta() { return true; }

$GLOBALS['options'] = array();
$GLOBALS['checks'] = 0;
function cadence_check( $ok, $message ) {
	$GLOBALS['checks']++;
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require $root . '/includes/class-cadence.php';

$zone = new DateTimeZone( 'America/Chicago' );
$at = static function ( $clock ) use ( $zone ) {
	return new DateTimeImmutable( '2026-11-21 ' . $clock, $zone );
};

cadence_check( array() === Lunara_Dispatch_Cadence::plan( $at( '08:30:00' ), 0 ), 'before 9:00 no draft is due' );
$morning = Lunara_Dispatch_Cadence::plan( $at( '10:15:00' ), 0 );
cadence_check( 1 === count( $morning ) && 'news' === $morning[0]['pillar'], 'the morning slot is news' );
$afternoon = Lunara_Dispatch_Cadence::plan( $at( '15:00:00' ), 0 );
cadence_check( 2 === count( $afternoon ), 'the afternoon plan is the daily minimum of two' );
$evening = Lunara_Dispatch_Cadence::plan( $at( '20:05:00' ), 0 );
cadence_check( 3 === count( $evening ) && array( 'news', 'reviews', 'oscars' ) === array( $evening[0]['pillar'], $evening[1]['pillar'], $evening[2]['pillar'] ), 'the evening plan is three slots across news, reviews, and the Oscars' );
cadence_check( array() === Lunara_Dispatch_Cadence::plan( $at( '20:05:00' ), 3 ), 'a day that already has three drafts does not open another slot' );

$rules = Lunara_Dispatch_Voice::rules();
cadence_check( count( $rules ) >= 4 && false !== strpos( implode( ' ', $rules ), 'first person' ) && false !== strpos( implode( ' ', $rules ), 'Lunara Film' ), 'the voice rules next to the composer are the Journal voice, in the first person' );

function cadence_topic( $pillar, $id, $film, array $extra = array() ) {
	return array_merge( array(
		'id'     => $id,
		'pillar' => $pillar,
		'title'  => $film . ' Is the Piece I Cannot Drop',
		'film'   => $film,
		'facts'  => array(
			'The trailer has been on my mind since this morning and I am not done with it.',
			'The choice on screen is specific enough to argue about instead of recap.',
		),
		'stake'  => 'I want this one on the desk because the taste is the point.',
		'source' => 'Empire',
		'url'    => 'https://example.test/' . $id,
	), $extra );
}

$topics = array(
	cadence_topic( 'oscars', 'oscars-race', 'The Odyssey' ),
	cadence_topic( 'news', 'news-eggers', 'Werwulf' ),
	cadence_topic( 'reviews', 'review-street', 'Street Fighter' ),
	cadence_topic( 'gossip', 'not-a-pillar', 'Ignored' ),
	cadence_topic( 'news', 'thin-item', 'Thin', array( 'facts' => array( 'Too short.' ) ) ),
	cadence_topic( 'news', 'banned-item', 'Banned', array( 'facts' => array(
		'It is worth noting that nothing here is a real stake for a reader.',
		'The second sentence cannot save a banned opener.',
	) ) ),
);
$chosen = Lunara_Dispatch_Cadence::select_topics( $topics, $evening );
cadence_check( 3 === count( $chosen ) && array( 'news', 'reviews', 'oscars' ) === array( $chosen[0]['pillar'], $chosen[1]['pillar'], $chosen[2]['pillar'] ), 'selection keeps one topic per slot inside news, reviews, and the Oscars' );
cadence_check( 'news-eggers' === $chosen[0]['id'] && 'review-street' === $chosen[1]['id'] && 'oscars-race' === $chosen[2]['id'], 'each slot takes its own pillar instead of the order the candidates arrived' );

$draft = Lunara_Dispatch_Local_Composer::compose( $chosen[0] );
cadence_check( is_array( $draft ), 'a qualified topic composes a draft' );
cadence_check( 1 === preg_match_all( '/<h3>/', $draft['html'] ) && preg_match_all( '/<p>/', $draft['html'] ) >= 2, 'the draft is an h3 and at least two paragraphs' );
cadence_check( false !== strpos( $draft['html'], '<em>Werwulf</em>' ) && false !== strpos( $draft['html'], 'I keep coming back' ), 'the film is marked and the opening is first person' );
cadence_check( 1 === substr_count( $draft['html'], 'Empire' ), 'the outlet is named once' );
cadence_check( false === strpos( $draft['html'], '<div' ) && false === strpos( $draft['html'], '<style' ) && false === strpos( $draft['html'], 'class=' ) && false === strpos( $draft['html'], '?' ), 'the draft is semantic copy without a question, a style tag, or a class' );
cadence_check( ! Lunara_Dispatch_Voice::contains_banned( $draft['html'] ), 'the composed draft does not use a banned house phrase' );
cadence_check( null === Lunara_Dispatch_Local_Composer::compose( cadence_topic( 'news', 'only-one', 'Only', array( 'facts' => array( 'One sentence is not enough to carry a Journal entry today.' ) ) ) ), 'one fact is not enough, so the composer skips it' );

$question = cadence_topic( 'reviews', 'question-stake', 'Alien' );
$question['stake'] = 'Is this the boldest swing in modern horror?';
$question_draft = Lunara_Dispatch_Local_Composer::compose( $question );
cadence_check( is_array( $question_draft ) && false === strpos( $question_draft['html'], '?' ), 'a question in the stake is not added to the draft' );

$saved = array();
$result = Lunara_Dispatch_Cadence::fill_due_slots( array(
	'now'        => $at( '20:05:00' ),
	'filed'      => 0,
	'candidates' => array(
		cadence_topic( 'news', 'news-eggers', 'Werwulf' ),
		cadence_topic( 'reviews', 'review-street', 'Street Fighter' ),
		cadence_topic( 'oscars', 'oscars-race', 'The Odyssey' ),
	),
	'insert'     => static function ( $payload, $record ) use ( &$saved ) {
		$saved[] = array( 'payload' => $payload, 'record' => $record );
		cadence_check( 'draft' === $record['post_status'], 'the record handed to storage is a draft' );
		cadence_check( ! isset( $payload['post_status'] ) || 'draft' === $payload['post_status'], 'the ingest payload does not ask for publication' );
		return array( 'post_status' => 'draft', 'post_id' => count( $saved ), 'created' => true );
	},
) );
cadence_check( 3 === $result['created'] && 0 === $result['shortfall'] && true === $result['hold'] && 'draft' === $result['post_status'], 'an evening run with three topics files three held drafts' );
cadence_check( array( 'news', 'reviews', 'oscars' ) === $result['pillars'], 'the three drafts cover news, reviews, and the Oscars' );

$again = Lunara_Dispatch_Cadence::fill_due_slots( array(
	'now'        => $at( '20:05:00' ),
	'filed'      => 3,
	'candidates' => array( cadence_topic( 'news', 'extra', 'Extra' ) ),
	'insert'     => static function () {
		cadence_check( false, 'a full day must not call storage' );
	},
) );
cadence_check( 0 === $again['created'] && 0 === $again['planned'], 'once three drafts exist, the schedule files nothing else' );

$borrowed = Lunara_Dispatch_Cadence::fill_due_slots( array(
	'now'    => $at( '20:05:00' ),
	'filed'  => 0,
	'candidates' => array(
		cadence_topic( 'reviews', 'review-a', 'Alien' ),
		cadence_topic( 'reviews', 'review-b', 'The Witch' ),
	),
	'insert' => static function ( $payload, $record ) {
		return array( 'post_status' => 'draft', 'created' => true );
	},
) );
cadence_check( 2 === $borrowed['created'] && 1 === $borrowed['shortfall'] && array( 'reviews', 'reviews' ) === $borrowed['pillars'], 'missing pillars are borrowed only from real topics, and a missing third topic is a shortfall' );

$GLOBALS['options']['lunara_journal_cadence_hold'] = '0';
$released = Lunara_Dispatch_Cadence::fill_due_slots( array(
	'now'        => $at( '10:15:00' ),
	'filed'      => 0,
	'candidates' => array( cadence_topic( 'news', 'news-eggers', 'Werwulf' ) ),
	'insert'     => static function ( $payload, $record ) {
		cadence_check( 'draft' === $record['post_status'] && 'ready' === $record['release'] && false === $record['hold'], 'lifting the hold marks the draft ready and still does not publish it' );
		return array( 'post_status' => 'publish', 'created' => true );
	},
) );
cadence_check( 0 === $released['created'] && ! empty( $released['errors'] ), 'a storage result that comes back published is refused' );

$GLOBALS['options']['lunara_journal_cadence_hold'] = '1';
$queued = Lunara_Dispatch_Cadence::persist_draft(
	array( 'content' => '<h3>Held</h3><p>I kept it.</p>', 'title' => 'Held' ),
	array( 'post_status' => 'draft', 'day' => '2026-11-21', 'pillar' => 'news', 'title' => 'Held' )
);
cadence_check( is_array( $queued ) && 'draft' === $queued['post_status'] && ! empty( $queued['queued'] ), 'without Foundation the draft is queued locally and stays a draft' );
$queue = get_option( Lunara_Dispatch_Cadence::QUEUE, array() );
cadence_check( is_array( $queue ) && 1 === count( $queue ) && 'draft' === $queue[0]['post_status'], 'the queued record is a draft' );
$refused = Lunara_Dispatch_Cadence::persist_draft( array( 'content' => 'no' ), array( 'post_status' => 'publish' ) );
cadence_check( is_wp_error( $refused ), 'persist refuses a publish status before it writes' );

require $root . '/includes/class-admin.php';
$admin = ( new ReflectionClass( 'Lunara_Dispatch_Admin' ) )->newInstanceWithoutConstructor();
cadence_check( '0' === $admin->sanitize_cadence_hold( '0' ) && '1' === $admin->sanitize_cadence_hold( '1' ) && '1' === $admin->sanitize_cadence_hold( 'anything' ), 'the settings control stores the hold as on unless it is explicitly off' );

fwrite( STDOUT, "Dispatch cadence runtime passed: {$GLOBALS['checks']} checks.\n" );
