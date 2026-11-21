<?php
/**
 * Draft cadence for the Lunara Film Journal.
 *
 * The clock and the publication hold live in Journal Foundation
 * (Lunara_Journal_Cadence). This file selects a topic inside news, reviews,
 * or the Oscars, composes a draft with the local voice scaffold, and stores
 * it as a draft. It never publishes.
 *
 * Lunara_Dispatch_Voice sits next to the composer on purpose. Dalton still
 * has to refine that voice. Until he does, lunara_journal_cadence_hold stays
 * on and nothing in this file is allowed to leave draft.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Dispatch_Voice {
	/**
	 * Draft voice, taken from the published Journal exemplars (the Eggers
	 * werewolf entry and the Street Fighter trailer entry) and from the
	 * standing Dispatch prompt. Refine these rules; do not replace them with
	 * a brighter, unrelated brand voice.
	 *
	 * @return string[]
	 */
	public static function rules() {
		return array(
			'Write in the first person, the way the published Journal does. The take is in the first sentence. The facts serve that take.',
			'Put film titles in em tags. Keep paragraphs short. The headline is an h3 of four to fourteen words, and it is not a feed recap or a template with the names swapped.',
			'Name the outlet once when one source supplies the item. Do not invent facts, scores, quotes, or certainty. Skip the item when the supplied facts are thinner than two concrete sentences.',
			'The stake is human: excitement, irritation, protectiveness, or taste. News is a film-world item with a reason to care. A review argues a film the desk already reviewed and does not invent a score. An Oscars piece is the race or the ledger, not an awards-consultant memo.',
			'Close on a landing sentence. Do not add a question. This local composer is a scaffold for Dalton to refine. It is not the finished Lunara Film voice, and its drafts stay unpublished until that sign-off.',
		);
	}

	/**
	 * Phrases the standing prompt already bans. The composer refuses a draft
	 * that would print one.
	 *
	 * @return string[]
	 */
	public static function banned_phrases() {
		return array(
			"it's worth noting",
			'worth noting',
			'notably',
			'the real story is',
			'the takeaway is simple',
			'delves into',
			'game-changer',
			'must-see',
			'highly anticipated',
			'only time will tell',
			'fans are eagerly awaiting',
			'a testament to',
			'in the current landscape',
			'unprecedented',
		);
	}

	public static function contains_banned( $text ) {
		$haystack = strtolower( wp_strip_all_tags( (string) $text ) );
		foreach ( self::banned_phrases() as $phrase ) {
			if ( false !== strpos( $haystack, $phrase ) ) {
				return true;
			}
		}
		return false;
	}

	public static function release_note() {
		if ( class_exists( 'Lunara_Journal_Cadence' ) ) {
			return Lunara_Journal_Cadence::release_note();
		}
		return 'Cadence drafts stay unpublished while lunara_journal_cadence_hold is on, which is the default. After the voice is signed off, set that option to 0. An editor then approves each draft in the Journal Desk delivery controls. This cadence never publishes a post by itself.';
	}
}

final class Lunara_Dispatch_Local_Composer {
	/**
	 * Turn one qualified topic into a Journal draft body.
	 * Returns null when the topic cannot be written without inventing facts.
	 *
	 * @param array $topic Normalized topic.
	 * @return array{title:string,html:string,excerpt:string}|null
	 */
	public static function compose( array $topic ) {
		$facts = isset( $topic['facts'] ) && is_array( $topic['facts'] ) ? array_values( $topic['facts'] ) : array();
		$clean = array();
		foreach ( $facts as $fact ) {
			$fact = trim( wp_strip_all_tags( (string) $fact ) );
			if ( '' === $fact || Lunara_Dispatch_Voice::contains_banned( $fact ) ) {
				continue;
			}
			$clean[] = $fact;
		}
		if ( count( $clean ) < 2 ) {
			return null;
		}
		$title = self::headline( $topic, $clean );
		if ( '' === $title || Lunara_Dispatch_Voice::contains_banned( $title ) ) {
			return null;
		}
		$film   = trim( wp_strip_all_tags( (string) ( $topic['film'] ?? '' ) ) );
		$source = trim( wp_strip_all_tags( (string) ( $topic['source'] ?? '' ) ) );
		$stake  = trim( wp_strip_all_tags( (string) ( $topic['stake'] ?? '' ) ) );
		if ( '' !== $stake && Lunara_Dispatch_Voice::contains_banned( $stake ) ) {
			$stake = '';
		}
		$subject = '' !== $film ? '<em>' . esc_html( $film ) . '</em>' : esc_html( $title );
		$opener  = 'I keep coming back to ' . $subject . '. ' . esc_html( $clean[0] );
		$second  = esc_html( $clean[1] );
		if ( '' !== $source ) {
			$second .= ' The reporting I am using is from ' . esc_html( $source ) . '.';
		}
		$paragraphs = array( $opener, $second );
		if ( '' !== $stake && $stake !== $clean[0] && $stake !== $clean[1] && false === strpos( $stake, '?' ) ) {
			$paragraphs[] = esc_html( rtrim( $stake, '.' ) ) . '.';
		}
		$html = '<h3>' . esc_html( $title ) . '</h3>';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . $paragraph . '</p>';
		}
		if ( Lunara_Dispatch_Voice::contains_banned( $html ) || false !== strpos( $html, '?' ) ) {
			return null;
		}
		$plain = trim( wp_strip_all_tags( $html ) );
		return array(
			'title'   => $title,
			'html'    => $html,
			'excerpt' => function_exists( 'wp_html_excerpt' ) ? wp_html_excerpt( $plain, 155 ) : substr( $plain, 0, 155 ),
		);
	}

	private static function headline( array $topic, array $facts ) {
		$title = trim( wp_strip_all_tags( (string) ( $topic['title'] ?? '' ) ) );
		$words = self::words( $title );
		if ( count( $words ) >= 4 && count( $words ) <= 14 ) {
			return $title;
		}
		$film  = trim( wp_strip_all_tags( (string) ( $topic['film'] ?? '' ) ) );
		$built = '' !== $film ? 'What I cannot shake about ' . $film : 'What I cannot shake about this story';
		$built_words = self::words( $built );
		if ( count( $built_words ) > 14 ) {
			$built = implode( ' ', array_slice( $built_words, 0, 14 ) );
		}
		while ( count( self::words( $built ) ) < 4 ) {
			$built .= ' today';
		}
		return $built;
	}

	private static function words( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? array() : explode( ' ', $text );
	}
}

final class Lunara_Dispatch_Cadence {
	const META  = '_lunara_journal_cadence';
	const QUEUE = 'lunara_dispatch_cadence_drafts';

	/**
	 * Same three slots as Lunara_Journal_Cadence. Foundation wins when it is loaded.
	 *
	 * @return array<int,array{index:int,pillar:string,at:string}>
	 */
	public static function plan( DateTimeImmutable $now, $filed ) {
		if ( class_exists( 'Lunara_Journal_Cadence' ) ) {
			return Lunara_Journal_Cadence::plan( $now, $filed );
		}
		$slots = array(
			array( 'pillar' => 'news', 'hour' => 9, 'minute' => 0 ),
			array( 'pillar' => 'reviews', 'hour' => 14, 'minute' => 0 ),
			array( 'pillar' => 'oscars', 'hour' => 19, 'minute' => 0 ),
		);
		$filed = max( 0, (int) $filed );
		$want  = 0;
		foreach ( $slots as $index => $slot ) {
			if ( $now >= $now->setTime( (int) $slot['hour'], (int) $slot['minute'], 0 ) ) {
				$want = $index + 1;
			}
		}
		$want = min( 3, $want );
		$open = max( 0, $want - min( $filed, 3 ) );
		$out  = array();
		for ( $n = 0; $n < $open; $n++ ) {
			$index = $filed + $n;
			if ( ! isset( $slots[ $index ] ) ) {
				break;
			}
			$slot  = $slots[ $index ];
			$out[] = array(
				'index'  => $index,
				'pillar' => $slot['pillar'],
				'at'     => $now->setTime( (int) $slot['hour'], (int) $slot['minute'], 0 )->format( 'c' ),
			);
		}
		return $out;
	}

	/**
	 * The existing Dispatch enabled switch pauses draft filing too.
	 * Tests and a Foundation-only load have no client, so filing stays available.
	 */
	private static function automation_allows_filing() {
		if ( class_exists( 'Lunara_Dispatch_Control_Plane_Client' ) && method_exists( 'Lunara_Dispatch_Control_Plane_Client', 'enabled' ) ) {
			return (bool) Lunara_Dispatch_Control_Plane_Client::enabled();
		}
		return true;
	}

	public static function hold_is_on() {
		if ( class_exists( 'Lunara_Journal_Cadence' ) ) {
			return Lunara_Journal_Cadence::hold_is_on();
		}
		$value = function_exists( 'get_option' ) ? get_option( 'lunara_journal_cadence_hold', '1' ) : '1';
		return ! in_array( (string) $value, array( '0', 'off', 'false' ), true );
	}

	public static function status_line() {
		$hold = self::hold_is_on();
		$lead = $hold
			? 'On. The day files two or three Journal drafts across news, reviews, and the Oscars, and publication is on hold.'
			: 'On. The publication hold is off. Drafts still wait for an editor to approve them in the Journal Desk.';
		return $lead . ' ' . Lunara_Dispatch_Voice::release_note();
	}

	/**
	 * File every slot that is due and not already filled. Drafts only.
	 *
	 * @param array $args now, filed, plan, candidates, insert.
	 * @return array
	 */
	public static function fill_due_slots( $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		if ( empty( $args['ignore_enabled'] ) && ! self::automation_allows_filing() ) {
			return array(
				'planned'     => 0,
				'created'     => 0,
				'shortfall'   => 0,
				'pillars'     => array(),
				'hold'        => self::hold_is_on(),
				'post_status' => 'draft',
				'errors'      => array( 'Dispatch automation is paused, so the cadence filed nothing.' ),
				'records'     => array(),
			);
		}
		$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now  = ( isset( $args['now'] ) && $args['now'] instanceof DateTimeImmutable ) ? $args['now'] : new DateTimeImmutable( 'now', $tz );
		$filed = array_key_exists( 'filed', $args ) ? (int) $args['filed'] : self::count_filed( $now );
		$plan  = isset( $args['plan'] ) && is_array( $args['plan'] ) ? $args['plan'] : self::plan( $now, $filed );
		$candidates = isset( $args['candidates'] ) && is_array( $args['candidates'] ) ? $args['candidates'] : self::gather_sources();
		$topics = self::select_topics( $candidates, $plan );
		$created = array();
		$errors  = array();
		foreach ( $topics as $topic ) {
			$draft = Lunara_Dispatch_Local_Composer::compose( $topic );
			if ( ! is_array( $draft ) ) {
				continue;
			}
			$record = array(
				'post_status'      => 'draft',
				'day'              => $now->format( 'Y-m-d' ),
				'pillar'           => $topic['pillar'],
				'intended_pillar'  => isset( $topic['slot']['pillar'] ) ? $topic['slot']['pillar'] : $topic['pillar'],
				'slot'             => isset( $topic['slot']['at'] ) ? $topic['slot']['at'] : '',
				'hold'             => self::hold_is_on(),
				'release'          => self::hold_is_on() ? 'hold' : 'ready',
				'title'            => $draft['title'],
				'idempotency_key'  => self::idempotency_key( $now, $topic ),
				'note'             => Lunara_Dispatch_Voice::release_note(),
			);
			$payload = array(
				'title'            => $draft['title'],
				'content'          => $draft['html'],
				'excerpt'          => $draft['excerpt'],
				'idempotency_key'  => $record['idempotency_key'],
				'topics'           => array( $topic['pillar'] ),
				'provenance'       => array(
					'provider'          => 'local-composer',
					'model'             => 'lunara-dispatch-cadence',
					'generated_at_gmt'  => gmdate( 'c' ),
				),
			);
			if ( isset( $args['insert'] ) && is_callable( $args['insert'] ) ) {
				$result = call_user_func( $args['insert'], $payload, $record );
			} else {
				$result = self::persist_draft( $payload, $record );
			}
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}
			if ( is_array( $result ) && isset( $result['post_status'] ) && 'draft' !== $result['post_status'] ) {
				$errors[] = 'Refused a non-draft cadence result.';
				continue;
			}
			$created[] = $record;
		}
		$pillars = array();
		foreach ( $created as $record ) {
			$pillars[] = $record['pillar'];
		}
		return array(
			'planned'     => count( $plan ),
			'created'     => count( $created ),
			'shortfall'   => max( 0, count( $plan ) - count( $created ) ),
			'pillars'     => $pillars,
			'hold'        => self::hold_is_on(),
			'post_status' => 'draft',
			'errors'      => $errors,
			'records'     => $created,
		);
	}

	/**
	 * One topic per open slot. Prefer the slot's pillar. Borrow another pillar
	 * inside news, reviews, and the Oscars when that slot's own source is empty.
	 *
	 * @param array $candidates Raw topics.
	 * @param array $slots      Open plan slots.
	 * @return array
	 */
	public static function select_topics( array $candidates, array $slots ) {
		$pool = array();
		foreach ( $candidates as $candidate ) {
			$topic = self::normalize_topic( $candidate );
			if ( $topic ) {
				$pool[] = $topic;
			}
		}
		$used    = array();
		$chosen  = array();
		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$want = isset( $slot['pillar'] ) ? (string) $slot['pillar'] : '';
			$pick = self::take_topic( $pool, $used, $want );
			if ( ! $pick ) {
				$pick = self::take_topic( $pool, $used, '' );
			}
			if ( ! $pick ) {
				continue;
			}
			$used[ $pick['id'] ] = true;
			$pick['slot'] = $slot;
			$chosen[] = $pick;
		}
		return $chosen;
	}

	public static function gather_sources() {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'lunara_dispatch_cadence_candidates', null );
			if ( is_array( $filtered ) ) {
				return $filtered;
			}
		}
		$topics = self::review_topics();
		$oscars = self::oscars_topic();
		if ( is_array( $oscars ) ) {
			$topics[] = $oscars;
		}
		return $topics;
	}

	/**
	 * Store a draft through Journal Foundation when it is active. Otherwise
	 * keep a local draft record. Never a public post.
	 *
	 * @param array $payload Ingest payload.
	 * @param array $record  Cadence meta.
	 * @return array|WP_Error
	 */
	public static function persist_draft( array $payload, array $record ) {
		if ( 'draft' !== ( $record['post_status'] ?? '' ) ) {
			return new WP_Error( 'lunara_cadence_status', 'Cadence refuses to write anything except a draft.' );
		}
		if ( class_exists( 'Lunara_Dispatch_Control_Plane_Client' ) ) {
			$result = Lunara_Dispatch_Control_Plane_Client::ingest_foundation_draft( $payload );
			if ( ! is_wp_error( $result ) ) {
				if ( function_exists( 'update_post_meta' ) && ! empty( $result['post_id'] ) ) {
					update_post_meta( (int) $result['post_id'], self::META, $record );
				}
				return $result;
			}
		}
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return array( 'post_status' => 'draft', 'queued' => true );
		}
		$queue = get_option( self::QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$queue[] = array_merge( $record, array(
			'content' => (string) ( $payload['content'] ?? '' ),
			'queued'  => true,
		) );
		update_option( self::QUEUE, array_slice( $queue, -40 ), false );
		return array( 'post_status' => 'draft', 'queued' => true );
	}

	public static function count_filed( DateTimeImmutable $now ) {
		$count = 0;
		$day   = $now->format( 'Y-m-d' );
		if ( function_exists( 'get_posts' ) && function_exists( 'get_post_meta' ) ) {
			$posts = get_posts( array(
				'post_type'      => 'journal',
				'post_status'    => array( 'draft', 'pending', 'publish', 'future', 'private' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'date_query'     => array( array(
					'after'     => $day . ' 00:00:00',
					'before'    => $day . ' 23:59:59',
					'inclusive' => true,
				) ),
			) );
			foreach ( (array) $posts as $post_id ) {
				$cadence = get_post_meta( (int) $post_id, self::META, true );
				$run     = get_post_meta( (int) $post_id, '_lunara_dispatch_run_id', true );
				if ( ( is_array( $cadence ) && ! empty( $cadence['pillar'] ) ) || ( is_string( $run ) && '' !== $run ) ) {
					$count++;
				}
			}
		}
		if ( function_exists( 'get_option' ) ) {
			$queue = get_option( self::QUEUE, array() );
			foreach ( is_array( $queue ) ? $queue : array() as $row ) {
				if ( is_array( $row ) && ( $row['day'] ?? '' ) === $day ) {
					$count++;
				}
			}
		}
		return $count;
	}

	private static function review_topics() {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$posts = get_posts( array(
			'post_type'           => 'review',
			'post_status'         => 'publish',
			'posts_per_page'      => 3,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		) );
		$out = array();
		foreach ( (array) $posts as $post ) {
			if ( ! is_object( $post ) || empty( $post->ID ) ) {
				continue;
			}
			$title = function_exists( 'get_the_title' ) ? trim( wp_strip_all_tags( (string) get_the_title( $post ) ) ) : '';
			$excerpt = function_exists( 'get_the_excerpt' ) ? trim( wp_strip_all_tags( (string) get_the_excerpt( $post ) ) ) : '';
			if ( '' === $title || '' === $excerpt ) {
				continue;
			}
			$film = $title;
			foreach ( array( ' – ', ' - ' ) as $separator ) {
				if ( false !== strpos( $title, $separator ) ) {
					$film = trim( strstr( $title, $separator, true ) );
					break;
				}
			}
			$out[] = array(
				'id'     => 'review-' . (int) $post->ID,
				'pillar' => 'reviews',
				'title'  => $title,
				'film'   => $film,
				'facts'  => array( $title, $excerpt ),
				'stake'  => '',
				'source' => 'Lunara Film Reviews',
				'url'    => function_exists( 'get_permalink' ) ? (string) get_permalink( $post ) : '',
			);
		}
		return $out;
	}

	/**
	 * One Oscars topic from the ledger the theme already measured: ceremony
	 * count, nomination count, and the Sweep Watch film. No invented winner.
	 */
	private static function oscars_topic() {
		if ( ! function_exists( 'lunara_get_home_ledger_band_data' ) ) {
			return null;
		}
		$band = lunara_get_home_ledger_band_data();
		if ( ! is_array( $band ) ) {
			return null;
		}
		$ceremonies  = (int) ( $band['ceremonies'] ?? 0 );
		$nominations = (int) ( isset( $band['record']['nominations'] ) ? $band['record']['nominations'] : 0 );
		$film = '';
		if ( function_exists( 'lunara_get_home_front_door_oscars' ) ) {
			$door = lunara_get_home_front_door_oscars();
			$film = is_array( $door ) ? trim( (string) ( $door['sweep'] ?? '' ) ) : '';
		}
		if ( $ceremonies < 1 || $nominations < 1 || '' === $film ) {
			return null;
		}
		return array(
			'id'     => 'oscars-ledger-' . $ceremonies . '-' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $film ) ),
			'pillar' => 'oscars',
			'title'  => 'Sweep Watch Is ' . $film . ' and the Ledger Is Open',
			'film'   => $film,
			'facts'  => array(
				'The Lunara Film Oscar Ledger is holding ' . $ceremonies . ' ceremonies and ' . $nominations . ' nominations.',
				'Sweep Watch on the desk is ' . $film . '.',
			),
			'stake'  => 'I want the race attached to the movie, not to an odds sheet.',
			'source' => 'The Lunara Film Oscar Ledger',
			'url'    => function_exists( 'home_url' ) ? home_url( '/oscars/' ) : '',
		);
	}

	private static function normalize_topic( $candidate ) {
		if ( ! is_array( $candidate ) ) {
			return null;
		}
		$pillar = isset( $candidate['pillar'] ) ? sanitize_key( (string) $candidate['pillar'] ) : '';
		if ( ! in_array( $pillar, array( 'news', 'reviews', 'oscars' ), true ) ) {
			return null;
		}
		$id = isset( $candidate['id'] ) ? sanitize_key( (string) $candidate['id'] ) : '';
		if ( '' === $id ) {
			return null;
		}
		$facts = array();
		foreach ( isset( $candidate['facts'] ) && is_array( $candidate['facts'] ) ? $candidate['facts'] : array() as $fact ) {
			$fact = trim( wp_strip_all_tags( (string) $fact ) );
			if ( strlen( $fact ) >= 12 && ! Lunara_Dispatch_Voice::contains_banned( $fact ) ) {
				$facts[] = $fact;
			}
		}
		if ( count( $facts ) < 2 ) {
			return null;
		}
		return array(
			'id'     => $id,
			'pillar' => $pillar,
			'title'  => trim( wp_strip_all_tags( (string) ( $candidate['title'] ?? '' ) ) ),
			'film'   => trim( wp_strip_all_tags( (string) ( $candidate['film'] ?? '' ) ) ),
			'facts'  => array_slice( $facts, 0, 4 ),
			'stake'  => trim( wp_strip_all_tags( (string) ( $candidate['stake'] ?? '' ) ) ),
			'source' => trim( wp_strip_all_tags( (string) ( $candidate['source'] ?? '' ) ) ),
			'url'    => trim( (string) ( $candidate['url'] ?? '' ) ),
		);
	}

	private static function take_topic( array $pool, array $used, $pillar ) {
		foreach ( $pool as $topic ) {
			if ( isset( $used[ $topic['id'] ] ) ) {
				continue;
			}
			if ( '' !== $pillar && $pillar !== $topic['pillar'] ) {
				continue;
			}
			return $topic;
		}
		return null;
	}

	private static function idempotency_key( DateTimeImmutable $now, array $topic ) {
		return 'cadence-' . $now->format( 'Ymd' ) . '-' . $topic['pillar'] . '-' . substr( md5( (string) $topic['id'] ), 0, 12 );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $text ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $text ) );
	}
}
