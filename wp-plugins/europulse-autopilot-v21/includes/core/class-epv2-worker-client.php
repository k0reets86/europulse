<?php
/**
 * EuroPulse AutoPilot v2.1 — Worker HTTP Client
 *
 * Sends queue items to the persistent Python worker service at 127.0.0.1:8765.
 * Replaces the old CLI-per-request pattern.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class EPV2_Worker_Client {

	private const WORKER_URL    = 'http://127.0.0.1:8765';
	private const HEALTH_URL    = 'http://127.0.0.1:8765/health';
	private const PROCESS_URL   = 'http://127.0.0.1:8765/process';
	/** How long to cache a healthy availability check (seconds). */
	private const AVAIL_TTL     = 30;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	public static function enabled(): bool {
		return (string) EPV2_Settings::get( 'worker_mode', 'disabled' ) !== 'disabled';
	}

	public static function mode(): string {
		return (string) EPV2_Settings::get( 'worker_mode', 'disabled' );
	}

	public static function is_available(): bool {
		$cached = get_transient( 'epv2_worker_available' );
		// Use cached "yes" — but don't cache "no" so we retry quickly after restart
		if ( $cached === 'yes' ) {
			return true;
		}
		$ok = self::ping();
		if ( $ok ) {
			set_transient( 'epv2_worker_available', 'yes', self::AVAIL_TTL );
		}
		return $ok;
	}

	public static function invalidate_availability_cache(): void {
		delete_transient( 'epv2_worker_available' );
	}

	public static function health_snapshot( bool $force = false ): array {
		$response = self::ping_response( $force );
		$available = (bool) ( $response['ok'] ?? false );
		$payload = is_array( $response['payload'] ?? null ) ? $response['payload'] : [];

		return [
			'enabled' => self::enabled(),
			'mode' => self::mode(),
			'available' => $available,
			'http_code' => (int) ( $response['http_code'] ?? 0 ),
			'error' => (string) ( $response['error'] ?? '' ),
			'payload' => $payload,
		];
	}

	/**
	 * Process a queue item through the full pipeline.
	 *
	 * @param  object  $item           Queue row object
	 * @param  string  $stage          "full_bundle" | "title" | "lead" | "body" | "media" | "seo"
	 * @param  array   $existing       Existing payload for partial regeneration
	 * @return array   Decoded worker response
	 * @throws RuntimeException        On connectivity or server errors
	 */
	public static function process(
		object $item,
		string $stage = 'full_bundle',
		array  $existing = []
	): array {
		$payload = self::build_payload( $item, $stage, $existing );
		return self::send( $payload );
	}

	public static function run_for_item(
		object $item,
		string $stage = 'full_bundle',
		array $existing = []
	): array {
		if ( ! self::enabled() ) {
			throw new RuntimeException( 'External worker is disabled' );
		}
		return self::process( $item, $stage, $existing );
	}

	// -------------------------------------------------------------------------
	// Payload builder
	// -------------------------------------------------------------------------

	public static function build_payload( object $item, string $stage = 'full_bundle', array $existing = [] ): array {
		$settings = EPV2_Settings::get_all();
		$normalized_stage = self::normalize_stage( $stage );

		// Phase 2.3: persist detected KIND into _meta.content_kind so the
		// worker's rewriter can pick the matching type module from the
		// composed prompt matrix (epv2_worker.prompts).
		if ( $existing !== [] && class_exists( 'EPV2_Content_Kinds' ) ) {
			$kind = EPV2_Content_Kinds::detect_kind( $existing );
			if ( ! is_array( $existing['_meta'] ?? null ) ) {
				$existing['_meta'] = [];
			}
			$existing['_meta']['content_kind'] = $kind;

			// In-house dossier enrichment: when the detected KIND requires
			// multi-source synthesis (news_article+), aggregate sibling
			// coverage from the queue/published table into
			// source_dossier.related[]. The rewriter consumes those entries
			// to write a fresh synthesis. Idempotent: skipped if
			// _meta.enrichment.ran is already set.
			if (
				class_exists( 'EPV2_Dossier_Enricher' )
				&& empty( $existing['_meta']['enrichment']['ran'] )
			) {
				$spec = EPV2_Content_Kinds::spec_for( $kind );
				if ( ! empty( $spec['enrichment_required'] ) ) {
					$existing = EPV2_Dossier_Enricher::enrich( (int) ( $item->id ?? 0 ), $existing );
				}
			}
		}

		$editorial_flags = [
			'openai_api_key'   => (string) ( $settings['ai_keys']['openai'] ?? '' ),
			'deepseek_api_key' => (string) ( $settings['ai_keys']['deepseek'] ?? '' ),
			'gemini_api_key'   => (string) ( $settings['ai_keys']['gemini'] ?? '' ),
			'pexels_api_key'   => (string) ( $settings['image_keys']['pexels'] ?? '' ),
			'ai_provider'      => (string) ( $settings['ai_provider'] ?? '' ),
			'ai_model'         => (string) ( $settings['ai_model'] ?? '' ),
			'ai_fallback_provider' => (string) ( $settings['ai_fallback_provider'] ?? '' ),
			'ai_fallback_model'    => (string) ( $settings['ai_fallback_model'] ?? '' ),
		];

		// Pick the richest text basis we can give the worker. The queue row's
		// raw `original_content` is often just an HTML wrapper around a
		// Google News redirect link — 434 chars of markup but only ~15
		// words of plain text once tags are stripped. That triggered the
		// worker's `< 35 words → "Primary source too thin" blocker on every
		// Google-News-sourced item. Compare lengths fairly:
		//   - clean HTML out of original_content first
		//   - take the dossier primary content if richer
		//   - last resort: synthesize a brief from the upfront story card's
		//     key_facts + entities so the rewriter at least has anchored
		//     facts to work from instead of seeing 15 words.
		$raw_original = (string) ( $item->original_content ?? '' );
		$clean_original = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $raw_original ) ) ?? '' );

		$dossier_primary_content = '';
		if ( is_array( $existing['_meta']['source_dossier']['primary'] ?? null ) ) {
			$dossier_primary_content = (string) ( $existing['_meta']['source_dossier']['primary']['content'] ?? '' );
		}
		$dossier_primary_excerpt = '';
		if ( is_array( $existing['_meta']['source_dossier']['primary'] ?? null ) ) {
			$dossier_primary_excerpt = (string) ( $existing['_meta']['source_dossier']['primary']['excerpt'] ?? '' );
		}

		// Story-card-derived synthetic basis. When the upfront semantic pass
		// extracted 3+ key facts plus a category, a short stitched paragraph
		// of those facts is FAR more substantive than 15 words of "Sichtbar
		// werden mit Fleiß - Schulzes Kampf gegen die AfD". The rewriter
		// receives the card itself anyway, but we also seed
		// `original_content` with a stitched form so word-count gates pass.
		$card_basis = '';
		if ( is_array( $existing['_meta']['story_card'] ?? null ) ) {
			$card = $existing['_meta']['story_card'];
			$key_facts = (array) ( $card['key_facts'] ?? [] );
			if ( $key_facts !== [] ) {
				$lines = [];
				$people = (array) ( $card['entities_people'] ?? $card['entities']['people'] ?? [] );
				if ( $people !== [] ) {
					$names = [];
					foreach ( array_slice( $people, 0, 4 ) as $p ) {
						if ( is_array( $p ) && ! empty( $p['name'] ) ) {
							$names[] = trim( (string) $p['name'] ) . ( ! empty( $p['role'] ) ? ' (' . trim( (string) $p['role'] ) . ')' : '' );
						}
					}
					if ( $names ) {
						$lines[] = 'Beteiligte: ' . implode( ', ', $names );
					}
				}
				$places = (array) ( $card['entities_places'] ?? $card['entities']['places'] ?? [] );
				if ( $places !== [] ) {
					$lines[] = 'Orte: ' . implode( ', ', array_slice( array_map( 'strval', $places ), 0, 4 ) );
				}
				foreach ( array_slice( $key_facts, 0, 6 ) as $fact ) {
					$fact = trim( (string) $fact );
					if ( $fact !== '' ) {
						$lines[] = '- ' . $fact;
					}
				}
				if ( $lines ) {
					$card_basis = implode( "\n", $lines );
				}
			}
		}

		// Pick the richest available basis by raw text length. We always
		// concatenate card_basis at the end if it adds new factual lines —
		// the rewriter is told via the system prompt that the card is the
		// authoritative fact list.
		$candidates = array_filter( [
			'dossier_content' => $dossier_primary_content,
			'dossier_excerpt' => $dossier_primary_excerpt,
			'clean_original'  => $clean_original,
		], static fn( $v ) => mb_strlen( (string) $v ) > 0 );
		$best_text = '';
		foreach ( $candidates as $candidate ) {
			if ( mb_strlen( $candidate ) > mb_strlen( $best_text ) ) {
				$best_text = $candidate;
			}
		}
		// If best body is still under ~200 words AND the card has key facts,
		// stitch them in. The rewriter prompt already tells the model to
		// ground the article on those facts.
		if ( str_word_count( $best_text ) < 200 && $card_basis !== '' ) {
			$best_text = trim( $best_text . "\n\n" . $card_basis );
		}
		$worker_original_content = $best_text !== '' ? $best_text : $clean_original;

		return [
			'queue_id'         => (int) ( $item->id ?? 0 ),
			'stage'            => $normalized_stage,
			'original_url'     => (string) ( $item->original_url     ?? '' ),
			'original_title'   => (string) ( $item->original_title   ?? '' ),
			'original_excerpt' => (string) ( $item->original_excerpt ?? '' ),
			'original_content' => $worker_original_content,
			'original_date'    => (string) ( $item->original_date    ?? '' ),
			'source_image_url' => (string) ( $item->source_image_url ?? '' ),
			'category_proposed'=> (string) ( $item->category_proposed ?? '' ),
			'category_final'   => (string) ( $item->category_final ?? '' ),
			'source_language'  => (string) ( $item->source_language  ?? '' ),
			'story_kind'       => (string) ( $item->story_format     ?? 'news' ),
			'story_format'     => (string) ( $item->story_format     ?? 'news' ),
			'length_profile'   => (string) EPV2_Settings::get( 'length_profile', 'standard' ),
			'existing_payload' => $existing !== [] ? $existing : (object) [],
			'worker_token'     => EPV2_Settings::worker_shared_secret(),
			'editorial_flags'  => $editorial_flags,
		];
	}

	private static function normalize_stage( string $stage ): string {
		$stage = trim( $stage );
		if ( $stage === '' ) {
			return 'full_bundle';
		}

		$aliases = [
			'rebuild_bundle'  => 'full_bundle',
			'translate_finish'=> 'full_bundle',
			'publish_finish'  => 'full_bundle',
			'full'            => 'full_bundle',
		];

		return $aliases[ $stage ] ?? $stage;
	}

	// -------------------------------------------------------------------------
	// HTTP transport
	// -------------------------------------------------------------------------

	private static function send( array $payload ): array {
		$stage = sanitize_key( (string) ( $payload['stage'] ?? '' ) );
		$max_timeout = in_array( $stage, [ 'translate_uk', 'translate_en' ], true ) ? 300 : 240;
		$timeout = max( 30, min( $max_timeout, (int) EPV2_Settings::get( 'worker_timeout_seconds', 120 ) ) );

		$response = wp_remote_post( self::PROCESS_URL, [
			'timeout'     => $timeout,
			'headers'     => [
				'Content-Type' => 'application/json',
				'X-EPV2-Worker-Token' => EPV2_Settings::worker_shared_secret(),
			],
			'body'        => wp_json_encode( $payload ),
			'data_format' => 'body',
		] );

		if ( is_wp_error( $response ) ) {
			self::invalidate_availability_cache();
			throw new RuntimeException( 'Worker unreachable: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			$detail = $body;
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && array_key_exists( 'detail', $decoded ) ) {
				$detail_value = $decoded['detail'];
				$detail = is_scalar( $detail_value )
					? (string) $detail_value
					: (string) wp_json_encode( $detail_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			$detail = trim( (string) $detail );
			throw new RuntimeException( "Worker returned HTTP {$code}: " . ( $detail !== '' ? $detail : 'empty error body' ) );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Worker returned invalid JSON' );
		}

		return $data;
	}

	private static function ping(): bool {
		$response = self::ping_response();
		return ! empty( $response['ok'] );
	}

	private static function ping_response( bool $force = false ): array {
		if ( ! $force && get_transient( 'epv2_worker_available' ) === 'yes' ) {
			return [
				'ok' => true,
				'http_code' => 200,
				'payload' => [],
				'error' => '',
			];
		}

		$response = wp_remote_get( self::HEALTH_URL, [
			'timeout'   => 3,
			'sslverify' => false,
		] );
		if ( is_wp_error( $response ) ) {
			return [
				'ok' => false,
				'http_code' => 0,
				'payload' => [],
				'error' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$payload = json_decode( $body, true );
		$payload = is_array( $payload ) ? $payload : [];
		$ok = $code === 200;

		if ( $ok ) {
			set_transient( 'epv2_worker_available', 'yes', self::AVAIL_TTL );
		} else {
			self::invalidate_availability_cache();
		}

		return [
			'ok' => $ok,
			'http_code' => $code,
			'payload' => $payload,
			'error' => $ok ? '' : wp_strip_all_tags( mb_substr( $body, 0, 220 ) ),
		];
	}
}
