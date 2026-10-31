<?php
/**
 * Behavior contract for the Dispatch schedule (3.4.4).
 *
 * On 2026-10-31 the active copy moved from `lunara-plugin-dispatch-main`
 * (3.4.2) to the deployed folder (3.4.3). Deactivating the old copy cleared
 * the every-4-hours run, as designed, but activating the new copy scheduled
 * nothing: activation runs after plugins_loaded, so the plugin's custom
 * intervals were not registered yet, and WordPress rejects an event with an
 * unknown interval. The checks:
 *
 * - activation schedules the run with the Control Plane's interval, before
 *   the plugin instance exists;
 * - a normal load puts a missing schedule back, once;
 * - deactivation clears it.
 *
 * The stubs follow WordPress: wp_schedule_event() returns false for an
 * interval that the cron_schedules filter has not registered.
 *
 * Run: php tests/dispatch-schedule-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_DIR', $root . DIRECTORY_SEPARATOR );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$checks = 0;
function dispatch_schedule_assert( $condition, $message ) {
	global $checks;
	++$checks;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['t'] = array( 'filters' => array(), 'events' => array(), 'schedule' => 'every_4_hours' );

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['t']['filters'][ $hook ][] = $callback; return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return add_filter( $hook, $callback, $priority, $args ); }
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['t']['filters'][ $hook ] ?? array() as $callback ) {
		$value = call_user_func( $callback, $value );
	}
	return $value;
}
function do_action( $hook ) {
	foreach ( $GLOBALS['t']['filters'][ $hook ] ?? array() as $callback ) {
		call_user_func( $callback );
	}
}
function __( $text ) { return $text; }
function is_admin() { return false; }
function get_option( $key, $default = false ) { return $default; }

/** WordPress: core intervals plus those the cron_schedules filter adds. */
function wp_get_schedules() {
	$core = array(
		'hourly'     => array( 'interval' => HOUR_IN_SECONDS ),
		'twicedaily' => array( 'interval' => 12 * HOUR_IN_SECONDS ),
		'daily'      => array( 'interval' => DAY_IN_SECONDS ),
		'weekly'     => array( 'interval' => 7 * DAY_IN_SECONDS ),
	);
	return array_merge( apply_filters( 'cron_schedules', array() ), $core );
}
/** WordPress: an event with an unknown interval is rejected. */
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	if ( ! isset( wp_get_schedules()[ $recurrence ] ) ) {
		return false;
	}
	$GLOBALS['t']['events'][] = array( 'hook' => $hook, 'time' => $timestamp, 'recurrence' => $recurrence );
	return true;
}
function wp_next_scheduled( $hook ) {
	foreach ( $GLOBALS['t']['events'] as $event ) {
		if ( $hook === $event['hook'] ) {
			return $event['time'];
		}
	}
	return false;
}
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['t']['events'] = array_values( array_filter( $GLOBALS['t']['events'], static function ( $event ) use ( $hook ) { return $hook !== $event['hook']; } ) );
	return true;
}

// Journal Foundation owns the Control Plane; Dispatch reads its schedule.
final class Lunara_Dispatch_Control_Plane_Client {
	public static function schedule() { return $GLOBALS['t']['schedule']; }
	public static function enabled() { return true; }
}
final class Lunara_Dispatch_Sources {
	public static function install_defaults_if_empty() {}
}

require_once $root . DIRECTORY_SEPARATOR . 'includes/class-plugin.php';

$hook = Lunara_Dispatch_Plugin::CRON_HOOK;

// The fixture rejects an unregistered interval, as WordPress does: this is
// what 3.4.3's activation ran into.
dispatch_schedule_assert( false === wp_schedule_event( time(), 'lunara_every_4_hours', $hook ) && array() === $GLOBALS['t']['events'], 'an interval nobody registered is rejected' );

// Activation, in a request where plugins_loaded has already run: no plugin
// instance, so only activation itself can register the intervals.
$before = time();
Lunara_Dispatch_Plugin::on_activate();
$events = $GLOBALS['t']['events'];
dispatch_schedule_assert( 1 === count( $events ) && $hook === $events[0]['hook'] && 'lunara_every_4_hours' === $events[0]['recurrence'], 'activation schedules the run every 4 hours, as the Control Plane says' );
dispatch_schedule_assert( $events[0]['time'] >= $before + HOUR_IN_SECONDS && $events[0]['time'] <= time() + HOUR_IN_SECONDS, 'the first run is an hour out' );
Lunara_Dispatch_Plugin::on_activate();
dispatch_schedule_assert( 1 === count( $GLOBALS['t']['events'] ), 'activating again does not add a second run' );

// Deactivation clears it.
Lunara_Dispatch_Plugin::on_deactivate();
dispatch_schedule_assert( false === wp_next_scheduled( $hook ), 'deactivation clears the run' );

// A normal load with the schedule missing (the live state after 3.4.3's
// activation): the next load puts it back, once.
$GLOBALS['t']['filters'] = array();
Lunara_Dispatch_Plugin::instance();
do_action( 'wp_loaded' );
dispatch_schedule_assert( 1 === count( $GLOBALS['t']['events'] ) && 'lunara_every_4_hours' === $GLOBALS['t']['events'][0]['recurrence'], 'a missing run comes back on the next load' );
do_action( 'wp_loaded' );
dispatch_schedule_assert( 1 === count( $GLOBALS['t']['events'] ), 'a later load leaves an existing run alone' );

// The interval follows the Control Plane.
wp_clear_scheduled_hook( $hook );
$GLOBALS['t']['schedule'] = 'every_2_hours';
do_action( 'wp_loaded' );
dispatch_schedule_assert( 'lunara_every_2_hours' === $GLOBALS['t']['events'][0]['recurrence'], 'the run follows the Control Plane interval' );
wp_clear_scheduled_hook( $hook );
$GLOBALS['t']['schedule'] = 'daily';
do_action( 'wp_loaded' );
dispatch_schedule_assert( 'daily' === $GLOBALS['t']['events'][0]['recurrence'], 'a daily Control Plane setting gives a daily run' );

fwrite( STDOUT, "Dispatch schedule runtime passed: {$checks} checks.\n" );
