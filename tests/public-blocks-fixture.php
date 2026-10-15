<?php
/** Real Dispatch renderer, simulated WordPress data. Not a full WordPress page. */
define('ABSPATH', dirname(__DIR__) . '/');
define('LUNARA_DISPATCH_URL', '/dispatch/');
define('LUNARA_DISPATCH_VERSION', 'fixture');
function add_filter(...$args) {}
function add_action(...$args) {}
function post_type_exists($type) { return true; }
function taxonomy_exists($type) { return true; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', $value); }
function sanitize_title($value) { return sanitize_key($value); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function esc_html__($value, $domain) { return esc_html($value); }
function esc_attr__($value, $domain) { return esc_attr($value); }
function wp_style_is(...$args) { return true; }
function wp_enqueue_style(...$args) {}
function wp_reset_postdata() {}
function is_wp_error($value) { return false; }
function get_the_ID() { return $GLOBALS['fixture_post']; }
function get_the_terms(...$args) { return [(object) ['name' => 'Journal']]; }
function has_post_thumbnail() { return get_the_ID() !== 2; }
function the_permalink() { echo '/journal/story-' . (int) get_the_ID() . '/'; }
function the_title() { echo 'A complete film headline with a longer title for the Journal'; }
function get_the_date($format) { return $format === 'c' ? '2026-10-15T12:00:00Z' : 'Oct 15, 2026'; }
function get_the_excerpt() { return 'A complete editorial argument survives the layout and remains readable.'; }
function wp_trim_words($value, $count) { return $value; }
function wpautop($value) { return '<p>' . $value . '</p>'; }
function wp_kses_post($value) { return $value; }
function the_post_thumbnail($size, $attributes) {
    $image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400"><rect width="640" height="400" fill="#293b49"/></svg>');
    echo '<img width="640" height="400" alt="Film artwork" src="' . $image . '" loading="' . esc_attr($attributes['loading']) . '">';
}
function get_terms($args) { return [(object) ['slug' => 'news', 'name' => 'News and film dispatches', 'count' => 12]]; }
function get_term_link($term) { return '/journal/type/' . $term->slug . '/'; }
class WP_Query {
    private $index = 0;
    public function __construct($args) {}
    public function have_posts() { return $this->index < 3; }
    public function the_post() { $GLOBALS['fixture_post'] = ++$this->index; }
}
require dirname(__DIR__) . '/includes/class-blocks.php';
$attributes = ['showImage' => true, 'showExcerpt' => true, 'showDate' => true, 'showType' => true];
foreach (['grid', 'list', 'rail'] as $layout) {
    echo '<section data-layout="' . $layout . '">';
    echo Lunara_Dispatch_Blocks::render_journal_feed($attributes + ['layout' => $layout]);
    echo '</section>';
}
echo '<section data-layout="spotlight">' . Lunara_Dispatch_Blocks::render_journal_spotlight($attributes) . '</section>';
echo '<section data-layout="lanes">' . Lunara_Dispatch_Blocks::render_journal_lanes(['showCounts' => true]) . '</section>';
