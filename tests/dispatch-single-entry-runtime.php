<?php
/**
 * Runtime check for the section splitter, on the real post builder (no stub).
 *
 * The Control Plane asks for one entry per run: an <h3> headline and
 * paragraphs. "Separate entries with <hr>" gives a lone entry no separator,
 * and until 3.4.6 the splitter returned no sections without one, so a run
 * that the model answered correctly wrote no draft.
 *
 * Run: php tests/dispatch-single-entry-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function get_bloginfo() { return 'UTF-8'; }

require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-post-builder.php';

$failures = array();
function single_entry_check( $ok, $message ) {
	global $failures;
	if ( ! $ok ) {
		$failures[] = $message;
	}
}

$builder = new Lunara_Dispatch_Post_Builder();
$entry   = '<h3>The Sequel Deal Is the Real Story</h3>'
	. '<p>Deadline reports the studio closed the sequel deal on Tuesday.</p>'
	. '<p>The terms say more than the announcement did.</p>';

// 1. The shape the prompt asks for: one headline, paragraphs, no separator.
$sections = $builder->extract_sections_with_body( $entry );
single_entry_check( 1 === count( $sections ), 'one entry with no <hr> did not become one section' );
single_entry_check( 'The Sequel Deal Is the Real Story' === ( $sections[0]['title'] ?? '' ), 'the <h3> headline did not become the title' );
single_entry_check( 0 === strpos( (string) ( $sections[0]['body'] ?? '' ), '<p>Deadline reports' ), 'the body does not open on the first paragraph' );
single_entry_check( false === stripos( (string) ( $sections[0]['body'] ?? '' ), '<h3' ), 'the headline is repeated inside the body' );
single_entry_check( false !== strpos( (string) ( $sections[0]['body'] ?? '' ), 'The terms say more' ), 'the second paragraph was lost' );

// 2. The same entry with a trailing separator is still exactly one section.
foreach ( array( '<hr>', '<hr />', "\n<hr class=\"wp-block-separator\"/>\n" ) as $separator ) {
	$trailing = $builder->extract_sections_with_body( $entry . $separator );
	single_entry_check( 1 === count( $trailing ) && 'The Sequel Deal Is the Real Story' === $trailing[0]['title'], 'a trailing separator changed the section count or title' );
}

// 3. Two entries separated by <hr> stay two sections, in order.
$second = '<h3>A Festival Sale Shows the Retreat</h3><p>Variety has the acquisition terms.</p>';
$pair   = $builder->extract_sections_with_body( $entry . '<hr>' . $second );
single_entry_check( 2 === count( $pair ), 'two <hr>-separated entries did not stay two sections' );
single_entry_check( 'A Festival Sale Shows the Retreat' === ( $pair[1]['title'] ?? '' ), 'the second entry lost its headline' );
single_entry_check( false === strpos( (string) ( $pair[0]['body'] ?? '' ), 'Variety' ), 'the first entry took text from the second' );

// 4. A Markdown code fence around the answer is removed, with or without a language tag.
foreach ( array( "```html\n" . $entry . "\n```", "```\n" . $entry . "\n```", "  ```html\r\n" . $entry . "\r\n```  " ) as $fenced ) {
	$unfenced = $builder->extract_sections_with_body( $fenced );
	single_entry_check( 1 === count( $unfenced ), 'a code-fenced entry did not become one section' );
	single_entry_check( false === strpos( (string) ( $unfenced[0]['body'] ?? '`' ), '`' ), 'the code fence leaked into the body' );
	single_entry_check( 'The Sequel Deal Is the Real Story' === ( $unfenced[0]['title'] ?? '' ), 'a code-fenced entry lost its headline' );
}
// A model that does both: a lead-in line, the fenced entry, then a chat line. None of it reaches the draft.
foreach ( array(
	'a lead-in before the fence'     => "Here is the entry:\n\n```html\n" . $entry . "\n```",
	'chat text after the fence'      => "```html\n" . $entry . "\n```\n\nLet me know if you would like a different angle.",
	'a lead-in and chat text'        => "Here is the entry:\r\n\r\n```html\r\n" . $entry . "\r\n```\r\n\r\nLet me know if you would like a different angle.",
	'a byte order mark and a fence'  => "\xEF\xBB\xBF```html\n" . $entry . "\n```",
) as $label => $chatty ) {
	$quiet = $builder->extract_sections_with_body( $chatty );
	$body  = (string) ( $quiet[0]['body'] ?? '`' );
	single_entry_check( 1 === count( $quiet ) && 'The Sequel Deal Is the Real Story' === $quiet[0]['title'], $label . ': the entry did not become one section with its headline' );
	single_entry_check( false === strpos( $body, '`' ) && false === strpos( $body, 'Let me know' ) && false === strpos( $body, 'Here is' ), $label . ': fence or chat text reached the body' );
	single_entry_check( 0 === strpos( $body, '<p>Deadline reports' ) && '</p>' === substr( $body, -4 ), $label . ': the body is not exactly the paragraphs' );
}
$fenced_pair = $builder->extract_sections_with_body( "Two entries:\n\n```html\n" . $entry . '<hr>' . $second . "\n```\n\nDone." );
single_entry_check( 2 === count( $fenced_pair ) && false === strpos( $fenced_pair[1]['body'], '`' ) && false === strpos( $fenced_pair[1]['body'], 'Done.' ), 'a fenced pair of entries kept the fence or the chat text' );

// A whole HTML document is reduced to its body: no <meta>, no <title> text, and the headline opens the entry.
foreach ( array(
	'a full document'           => "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<title>Journal</title>\n<style>p{margin:0}</style>\n</head>\n<body>\n" . $entry . "\n</body>\n</html>",
	'a fenced full document'    => "```html\n<!DOCTYPE html>\n<html><head><title>Journal</title></head><body class=\"x\">" . $entry . "</body></html>\n```",
	'html and body only'        => '<html><body>' . $entry . '</body></html>',
	'a body that never closes'  => '<html><head><title>Journal</title></head><body>' . $entry,
) as $label => $document ) {
	$from_document = $builder->extract_sections_with_body( $document );
	$body          = (string) ( $from_document[0]['body'] ?? '<h3' );
	single_entry_check( 1 === count( $from_document ) && 'The Sequel Deal Is the Real Story' === $from_document[0]['title'], $label . ': the entry did not become one section with its headline' );
	single_entry_check( 0 === strpos( $body, '<p>Deadline reports' ), $label . ': the body does not open on the first paragraph' );
	foreach ( array( '<h3', '<meta', '<title', '<style', '<body', '</html', 'Journal</title>' ) as $leak ) {
		single_entry_check( false === stripos( $body, $leak ), $label . ': ' . $leak . ' reached the body' );
	}
}

single_entry_check( $entry === Lunara_Dispatch_Post_Builder::unwrap_code_fence( $entry ), 'output with no fence was changed' );
$fence_after_markup = '<h3>Title</h3><p>The command is:</p>' . "\n```\nrun --fast\n```";
single_entry_check( $fence_after_markup === Lunara_Dispatch_Post_Builder::unwrap_code_fence( $fence_after_markup ), 'a fence that comes after markup was removed' );
$inline_ticks = '<h3>Title</h3><p>The script calls it ```raw``` footage.</p>';
single_entry_check( $inline_ticks === Lunara_Dispatch_Post_Builder::unwrap_code_fence( $inline_ticks ), 'backticks inside the entry were treated as a fence' );

// 5. A plain lead-in line or a comment before the headline is dropped; the headline is not repeated in the body.
foreach ( array( "Here is the entry:\n\n" . $entry, '<!-- one entry -->' . $entry ) as $with_preamble ) {
	$clean = $builder->extract_sections_with_body( $with_preamble );
	single_entry_check( 1 === count( $clean ) && 'The Sequel Deal Is the Real Story' === $clean[0]['title'], 'an entry with a lead-in lost its headline' );
	single_entry_check( 0 === strpos( (string) ( $clean[0]['body'] ?? '' ), '<p>Deadline reports' ), 'the lead-in or the headline stayed in the body' );
}

// 6. Markup before the headline is content and stays.
$lead_markup = $builder->extract_sections_with_body( '<p>Dateline paragraph.</p>' . $entry );
single_entry_check( 1 === count( $lead_markup ) && false !== strpos( $lead_markup[0]['body'], 'Dateline paragraph.' ), 'a paragraph before the headline was dropped' );

// 7. What is not an entry is still not an entry.
foreach ( array(
	'empty output'           => '',
	'whitespace'             => "  \n ",
	'a refusal in prose'     => 'I cannot write this entry because the source is too thin.',
	'a headline alone'       => '<h3>Only a Headline</h3>',
	'a paragraph alone'      => '<p>A paragraph with no headline and no separator.</p>',
	'an empty fence'         => "```html\n```",
) as $label => $not_an_entry ) {
	single_entry_check( array() === $builder->extract_sections_with_body( $not_an_entry ), $label . ' became a section' );
}

// 8. An <hr>-separated part with no headline keeps the older behavior: its opening paragraph names it.
$untitled = $builder->extract_sections_with_body( '<p>First story opens here.</p><p>More.</p><hr>' . $second );
single_entry_check( 2 === count( $untitled ) && 'First story opens here.' === $untitled[0]['title'], 'an untitled <hr> part lost its paragraph-derived title' );

// 9. The older <h2> format still splits first.
$legacy = $builder->extract_sections_with_body( '<h2>Alpha</h2><p>One.</p><h2>Beta</h2><p>Two.</p>' );
single_entry_check( 2 === count( $legacy ) && 'Alpha' === $legacy[0]['title'] && 'Beta' === $legacy[1]['title'], 'the <h2> format no longer splits' );

if ( $failures ) {
	fwrite( STDERR, "Dispatch single-entry runtime failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Dispatch single-entry runtime passed.\n";
