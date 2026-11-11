<?php
/**
 * Runtime check for the runs that end with no draft and used to leave no trace
 * or repeat forever (3.4.6):
 *  - a model answer that cannot be read as an entry is retried once, then the
 *    source item is set aside (feed item marked seen, approved pitch settled);
 *  - a run that throws writes a failed report and releases the worker lock;
 *  - the settings screen can count the runs since the last new draft.
 * Run: php tests/dispatch-unreadable-output-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_DIR', $root . DIRECTORY_SEPARATOR );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$unreadable_options  = array();
$unreadable_failures = array();

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_admin() { return false; }
function add_filter() { return true; }
function add_action() { return true; }
function __( $value ) { return $value; }
function wp_generate_uuid4() { static $n = 0; return 'owner-' . ( ++$n ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_cache_delete() { return true; }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function get_bloginfo() { return 'UTF-8'; }
function wp_http_validate_url( $url ) { return $url; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function current_time() { return '2026-11-11 12:00:00'; }
function get_date_from_gmt( $value, $format = 'Y-m-d H:i:s' ) { return gmdate( $format, strtotime( $value . ' UTC' ) ); }
function get_post_thumbnail_id() { return 0; }
function current_user_can( $cap ) { return 'edit_others_posts' === $cap; }
function rest_ensure_response( $value ) { return $value; }
function register_rest_route() { return true; }
function wp_next_scheduled() { return false; }
function wp_schedule_single_event() { return true; }
function spawn_cron() { return true; }
function delete_option( $key ) {
    global $unreadable_options;
    unset( $unreadable_options[ $key ] );
    return true;
}
function add_option( $key, $value ) {
    global $unreadable_options;
    if ( array_key_exists( $key, $unreadable_options ) ) {
        return false;
    }
    $unreadable_options[ $key ] = $value;
    return true;
}
function get_option( $key, $default = false ) {
    global $unreadable_options;
    return array_key_exists( $key, $unreadable_options ) ? $unreadable_options[ $key ] : $default;
}
function update_option( $key, $value ) {
    global $unreadable_options;
    $unreadable_options[ $key ] = $value;
    return true;
}

// A cooperative options table: the worker lock is granted, heartbeats and
// release succeed, exactly as they would for the only running worker.
class Unreadable_Wpdb {
    public $options = 'wp_options';
    public function prepare( $query, ...$args ) { return array( $query, $args ); }
    public function get_var( $prepared ) {
        global $unreadable_options;
        return $unreadable_options[ $prepared[1][0] ] ?? null;
    }
    public function query( $prepared ) {
        global $unreadable_options;
        list( $sql, $args ) = $prepared;
        if ( 0 === strpos( $sql, 'UPDATE' ) ) {
            if ( ( $unreadable_options[ $args[1] ] ?? null ) !== $args[2] ) {
                return 0;
            }
            $changed = $unreadable_options[ $args[1] ] !== $args[0];
            $unreadable_options[ $args[1] ] = $args[0];
            return $changed ? 1 : 0;
        }
        if ( 0 === strpos( $sql, 'DELETE' ) && ( $unreadable_options[ $args[0] ] ?? null ) === $args[1] ) {
            unset( $unreadable_options[ $args[0] ] );
            return 1;
        }
        return 0;
    }
}
$wpdb = new Unreadable_Wpdb();

function unreadable_story( $n, array $extra = array() ) {
    return array_merge( array(
        'title'        => "Story $n",
        'url'          => "https://example.com/story-$n",
        'description'  => "What the outlet reported about story $n.",
        'source_label' => 'Deadline',
        'fingerprint'  => md5( "https://example.com/story-$n" ),
        'published_at' => '2026-11-11T11:00:00+00:00',
    ), $extra );
}

// A feed that behaves like the real one: a story marked seen is not offered again.
class Unreadable_Feed {
    public $calls = 0;
    public $items = array();
    public $seen  = array();
    public function fetch_all() {
        $this->calls++;
        $fresh = array();
        foreach ( $this->items as $item ) {
            if ( ! in_array( $item['url'], $this->seen, true ) ) {
                $fresh[] = $item;
            }
        }
        return array( 'items' => $fresh, 'skipped_duplicates' => 0, 'errors' => array() );
    }
    public function mark_seen( array $items ) {
        foreach ( $items as $item ) {
            $this->seen[] = $item['url'];
        }
    }
    public function load_seen_sources() { return array(); }
}

class Unreadable_Reader {
    public function hydrate_items( array $items ) {
        return array( 'items' => $items, 'ready' => count( $items ), 'fallback' => 0, 'cache_hits' => 0, 'errors' => array() );
    }
}

class Unreadable_AI {
    public $calls = 0;
    public $throw = null;
    public function generate( $news_data ) {
        unset( $news_data );
        $this->calls++;
        if ( $this->throw ) {
            throw $this->throw;
        }
        return 'I looked at these stories and none of them has a headline to give.';
    }
}

// The builder reports what the real one reports for output with no entry in
// it: no post, no gate skip, no insertion failure. $readable flips it to a run
// that writes one draft per item.
class Unreadable_Builder {
    public $readable = false;
    public $next_id  = 500;
    public function get_target_post_type() { return 'journal'; }
    public function split_into_individual_posts( $generated, $a, $type, $status, $context ) {
        unset( $generated, $a, $type, $status );
        if ( ! $this->readable ) {
            return array();
        }
        $ids = array();
        foreach ( $context['items'] as $item ) {
            $ids[] = ++$this->next_id;
        }
        return $ids;
    }
    public function get_last_topic_duplicate_skips() { return array(); }
    public function get_last_quality_gate_skips() { return array(); }
    public function get_last_insertion_failures() { return array(); }
}

class Unreadable_Images {
    public function assign_images_to_posts() { return array( 'sideloaded' => 0, 'matched' => 0 ); }
}

class Lunara_Journal_Control_Plane {}

class Lunara_Dispatch_Control_Plane_Client {
    public static function available() { return true; }
    public static function runtime_config() { return array( 'protocol_version' => '1.2.1', 'enabled' => true, 'config_version' => '7' ); }
    public static function enabled() { return true; }
    public static function post_status() { return 'draft'; }
    public static function provider() { return 'claude'; }
    public static function model_for_provider() { return 'test-model'; }
}

class Lunara_Journal_Automation {
    public static $signals  = array();
    public static $outcomes = array();
    public static function dispatch_source_items() { return self::$signals; }
    public static function record_dispatch_source_outcome( array $ids, $outcome ) {
        foreach ( $ids as $id ) {
            self::$outcomes[ $id ] = $outcome;
        }
        return count( $ids );
    }
}

function unreadable_check( $ok, $message ) {
    global $unreadable_failures;
    if ( ! $ok ) {
        $unreadable_failures[] = $message;
    }
}

require_once $root . DIRECTORY_SEPARATOR . 'includes/class-plugin.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes/class-pitches.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes/class-admin.php';

$plugin = Lunara_Dispatch_Plugin::instance();
$plugin->feed_fetcher  = new Unreadable_Feed();
$plugin->source_reader = new Unreadable_Reader();
$plugin->ai_client     = new Unreadable_AI();
$plugin->post_builder  = new Unreadable_Builder();
$plugin->image_handler = new Unreadable_Images();

$attempts_option = Lunara_Dispatch_Plugin::UNREADABLE_OPTION;
$limit           = Lunara_Dispatch_Plugin::MAX_UNREADABLE_ATTEMPTS;
unreadable_check( 2 === $limit, 'this test walks a two-attempt limit; update it with the constant' );

// 1. First unreadable answer: nothing is used up, the items wait for one more run.
$plugin->feed_fetcher->items = array( unreadable_story( 1 ), unreadable_story( 2 ) );
$first = $plugin->run( false );
unreadable_check( empty( $first['success'] ) && ! empty( $first['retry_required'] ), 'the first unreadable run did not ask for a retry' );
unreadable_check( false !== strpos( $first['message'], '2 source item(s) stay eligible for one more run' ), 'the first unreadable run did not say the items stay eligible' );
unreadable_check( array() === $plugin->feed_fetcher->seen, 'the first unreadable run marked items seen' );
unreadable_check( 2 === count( get_option( $attempts_option, array() ) ), 'the first unreadable run did not count one attempt per item' );

// 2. Second unreadable answer for the same items: they are set aside and never sent again.
$second = $plugin->run( false );
unreadable_check( empty( $second['success'] ) && empty( $second['retry_required'] ), 'the second unreadable run still asked for a retry' );
unreadable_check( false !== strpos( $second['message'], '2 source item(s) set aside after 2 attempts' ), 'the second unreadable run did not say the items were set aside' );
unreadable_check( 2 === count( $plugin->feed_fetcher->seen ), 'items that used every attempt were not marked seen' );
unreadable_check( array() === get_option( $attempts_option, array() ), 'attempt counters were kept for items that were set aside' );
$calls_before_third = $plugin->ai_client->calls;
$third = $plugin->run( false );
unreadable_check( ! empty( $third['success'] ) && false !== strpos( $third['message'], 'No new items' ), 'a third run did not find the queue empty' );
unreadable_check( $calls_before_third === $plugin->ai_client->calls, 'a set-aside item was sent to the model again' );

// 3. Attempts are counted per item: a story on its second attempt is set aside while a new one beside it waits.
$plugin->feed_fetcher->items = array( unreadable_story( 3 ) );
$plugin->run( false );
$plugin->feed_fetcher->items = array( unreadable_story( 3 ), unreadable_story( 4 ) );
$mixed = $plugin->run( false );
unreadable_check( ! empty( $mixed['retry_required'] ) && false !== strpos( $mixed['message'], '1 source item(s) stay eligible for one more run' ), 'a mixed run did not keep the new item eligible' );
unreadable_check( in_array( 'https://example.com/story-3', $plugin->feed_fetcher->seen, true ), 'the item on its second attempt was not set aside' );
unreadable_check( ! in_array( 'https://example.com/story-4', $plugin->feed_fetcher->seen, true ), 'the item on its first attempt was set aside early' );

// 4. A run that writes the story clears its counter.
$plugin->post_builder->readable = true;
$written = $plugin->run( false );
unreadable_check( ! empty( $written['success'] ) && 1 === $written['created'], 'a readable answer did not write the waiting story' );
unreadable_check( array() === get_option( $attempts_option, array() ), 'a written story kept its unreadable-attempt counter' );
$plugin->post_builder->readable = false;

// 5. An approved pitch cannot stay in front of the feeds: two unreadable answers settle it as skipped.
$plugin->feed_fetcher->items = array();
$plugin->feed_fetcher->seen  = array();
Lunara_Dispatch_Pitches::add_items( array( unreadable_story( 9 ) ) );
$open_pitches = Lunara_Dispatch_Pitches::all();
$pitch_id     = (string) $open_pitches[0]['id'];
Lunara_Dispatch_Pitches::decide( array( $pitch_id ), array() );
unreadable_check( Lunara_Dispatch_Pitches::has_approved(), 'the test pitch was not approved' );
$feed_calls = $plugin->feed_fetcher->calls;
$pitch_first = $plugin->run( false );
unreadable_check( Lunara_Dispatch_Pitches::has_approved(), 'one unreadable answer already settled the approved pitch' );
unreadable_check( empty( $pitch_first['success'] ) && ! empty( $pitch_first['retry_required'] ), 'the first unreadable pitch run did not ask for a retry' );
$pitch_second = $plugin->run( false );
$settled      = Lunara_Dispatch_Pitches::all();
unreadable_check( ! Lunara_Dispatch_Pitches::has_approved(), 'an approved pitch stayed approved after two unreadable answers' );
unreadable_check( 'skipped' === ( $settled[0]['status'] ?? '' ), 'the unreadable pitch was not settled as skipped' );
unreadable_check( false !== strpos( (string) ( $settled[0]['note'] ?? '' ), 'no readable entry' ), 'the settled pitch does not say why it was skipped' );
unreadable_check( $feed_calls === $plugin->feed_fetcher->calls, 'a run with an approved pitch pulled the feeds' );
$plugin->run( false );
unreadable_check( $feed_calls + 1 === $plugin->feed_fetcher->calls, 'the feeds were not pulled once the pitch was settled' );

// 6. A run that throws writes a failed report and releases the lock.
$plugin->feed_fetcher->items = array( unreadable_story( 20 ) );
$plugin->ai_client->throw    = new RuntimeException( 'The provider client broke <b>badly</b>.' );
$thrown = $plugin->run( false );
unreadable_check( empty( $thrown['success'] ) && ! empty( $thrown['retry_required'] ), 'a run that threw did not return a failed result' );
unreadable_check( false !== strpos( $thrown['message'], 'stopped on an internal error' ) && false !== strpos( $thrown['message'], 'RuntimeException' ), 'the failed result does not name the error' );
unreadable_check( false === strpos( $thrown['message'], '<b>' ), 'the error text was not cleaned' );
$report = $plugin->get_last_run_report();
unreadable_check( isset( $report['success'] ) && false === $report['success'] && 'internal_error' === ( $report['ai_error_code'] ?? '' ), 'a run that threw left no failed report' );
unreadable_check( $thrown['message'] === ( $plugin->get_run_history()[0]['message'] ?? '' ), 'a run that threw is missing from the run history' );
unreadable_check( ! in_array( 'https://example.com/story-20', $plugin->feed_fetcher->seen, true ), 'a run that threw marked its items seen' );
$plugin->ai_client->throw      = null;
$plugin->post_builder->readable = true;
$after_throw = $plugin->run( false );
unreadable_check( ! empty( $after_throw['success'] ) && 1 === $after_throw['created'], 'the lock was not released after a run that threw' );

// 7. The settings screen reads the stored history: rows newest first, and the count of runs since a draft.
$history = $plugin->get_run_history();
$rows    = Lunara_Dispatch_Admin::summarize_run_history( $history );
unreadable_check( count( $rows ) === count( $history ) && count( $rows ) > 5, 'the history rows do not match the stored reports' );
unreadable_check( true === $rows[0]['ok'] && 1 === $rows[0]['created'], 'the newest history row is not the run that wrote a draft' );
unreadable_check( false === $rows[1]['ok'] && 0 === $rows[1]['created'] && false !== strpos( $rows[1]['message'], 'internal error' ), 'the failed run is not the second history row' );
unreadable_check( 0 === Lunara_Dispatch_Admin::runs_since_last_draft( $history ), 'a history that opens on a written draft did not count zero quiet runs' );
unreadable_check( 2 === Lunara_Dispatch_Admin::runs_since_last_draft( array(
    array( 'success' => true, 'created' => 0 ),
    array( 'success' => false ),
    array( 'success' => true, 'created' => 2 ),
    array( 'success' => true, 'created' => 0 ),
) ), 'quiet runs were not counted back to the last draft' );
unreadable_check( 2 === Lunara_Dispatch_Admin::runs_since_last_draft( array( array( 'created' => 0 ), array( 'created' => 0 ) ) ), 'a history with no draft did not count every run' );
unreadable_check( 0 === Lunara_Dispatch_Admin::runs_since_last_draft( 'not a list' ) && array() === Lunara_Dispatch_Admin::summarize_run_history( false ), 'a damaged history option was not handled' );
$odd_rows = Lunara_Dispatch_Admin::summarize_run_history( array( 'junk', array( 'success' => true ) ) );
unreadable_check( 1 === count( $odd_rows ) && 'time not recorded' === $odd_rows[0]['when'] && 'No run message recorded.' === $odd_rows[0]['message'], 'a report with missing fields was not shown safely' );

if ( $unreadable_failures ) {
    fwrite( STDERR, "Dispatch unreadable-output runtime failed:\n- " . implode( "\n- ", $unreadable_failures ) . "\n" );
    exit( 1 );
}

echo "Dispatch unreadable-output runtime passed.\n";
