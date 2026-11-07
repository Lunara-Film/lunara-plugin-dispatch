<?php
/**
 * The Dispatch settings screen owns the pitch mode switch (3.4.5).
 *
 * Run: php tests/dispatch-pitch-mode-setting-runtime.php
 *
 * Runs the production sanitizer the Settings API calls when the form saves, and reads the screen for the
 * one switch in both of its branches, so a silent Journal can be put back on the feeds from wp-admin.
 */
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/includes/class-admin.php' );
$checks = 0;
function pm_assert( $condition, $message ) {
    ++$GLOBALS['checks'];
    if ( ! $condition ) { fwrite( STDERR, "Pitch mode setting: $message\n" ); exit( 1 ); }
}
pm_assert( is_string( $source ) && '' !== $source, 'includes/class-admin.php is readable' );

$start = strpos( $source, 'public function sanitize_pitch_mode(' );
pm_assert( false !== $start, 'the sanitizer exists' );
$method = ''; $depth = 0; $opened = false;
foreach ( token_get_all( '<?php ' . substr( $source, $start ) ) as $token ) {
    if ( is_array( $token ) ) { if ( T_OPEN_TAG !== $token[0] ) { $method .= $token[1]; } continue; }
    $method .= $token;
    if ( '{' === $token ) { ++$depth; $opened = true; }
    if ( '}' === $token && 0 === --$depth && $opened ) { break; }
}
eval( 'class PitchModeAdminFixture {' . $method . '}' );
$admin = new PitchModeAdminFixture();
foreach ( array( '1', 1, 'on', true, 'yes' ) as $on ) {
    pm_assert( 1 === $admin->sanitize_pitch_mode( $on ), 'a checked box saves 1 (' . var_export( $on, true ) . ')' );
}
foreach ( array( null, '', '0', 0, false, array() ) as $off ) {
    pm_assert( 0 === $admin->sanitize_pitch_mode( $off ), 'an unchecked box, which the Settings API hands over as nothing, saves 0 (' . var_export( $off, true ) . ')' );
}

// The option is saved by the screen's own form, in the same group the form submits.
pm_assert( 1 === preg_match( "/register_setting\('lunara_dispatch_options', 'lunara_dispatch_pitch_mode'/", $source ), 'the option is registered in the settings group the form submits' );
pm_assert( false !== strpos( $source, "settings_fields('lunara_dispatch_options')" ), 'the form submits that group' );
// Both branches of the screen (Journal Foundation ready, legacy recovery) carry the switch.
pm_assert( 2 === substr_count( $source, "\$this->row('Pitch Mode', \$pitch_mode_field)" ), 'the switch is on the screen in both branches' );
pm_assert( false !== strpos( $source, 'name="lunara_dispatch_pitch_mode"' ), 'the switch posts the option name' );
pm_assert( false !== strpos( $source, '<strong>Pitch mode:</strong>' ), 'Automation Health states the mode' );
echo "Pitch mode setting passed: $checks checks.\n";
