<?php
/**
 * EuroPulse AutoPilot v2.1 — Story Card Builder
 *
 * Single upfront semantic pass that turns a raw queue row into a structured
 * "story card" stored in `ai_payload._meta.story_card`. The card is consumed
 * by every downstream stage (categorizer override, media resolver,
 * rewriter structure hints, SEO, tagger) so all of them work from the same
 * understanding instead of running their own keyword heuristic.
 *
 * Talks to the Python worker's `/analyze_story` endpoint via `wp_remote_post`.
 * Falls back gracefully (returns an empty card with `success=false`) so
 * callers can keep going with the legacy heuristic categorizer when the
 * worker is offline or hits an error.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EPV2_Story_Card_Builder {

	private const ENDPOINT_PATH = '/analyze_story';
	private const REQUEST_TIMEOUT = 45;
	// P1.8 (2026-05-11): bump when story_card.py prompt changes — invalidates
	// cached cards с outdated editorial_match / per-rubric stop-lists.
	// Cards без current version (или с stale version) treated as missing
	// at process_scheduled upfront check → rebuild fresh on next tick.
	public const STORY_CARD_PROMPT_VERSION = '2026-05-21-v2';

	/**
	 * Build a story card for a queue item.
	 *
	 * @param object $item    Queue row (must have original_url, original_title, original_excerpt, original_content).
	 * @param array  $dossier Optional source dossier (used for richer body text).
	 * @return array Story card; `['success' => false, 'error' => ...]` on failure.
	 */
	public static function build( object $item, array $dossier = [] ): array {
		if ( ! self::worker_available() ) {
			return self::empty_card( 'worker_unavailable' );
		}

		$body_source = self::pick_body_text( $item, $dossier );
		$ai_provider = (string) EPV2_Settings::get( 'ai_provider', 'openai' );
		$ai_model = (string) EPV2_Settings::get( 'ai_model', 'gpt-4o-mini' );
		$fallback_provider = (string) EPV2_Settings::get( 'ai_fallback_provider', '' );
		$fallback_model = (string) EPV2_Settings::get( 'ai_fallback_model', '' );
		$openai_model = 'gpt-4o-mini';
		if ( $ai_provider === 'openai' && $ai_model !== '' ) {
			$openai_model = $ai_model;
		} elseif ( $fallback_provider === 'openai' && $fallback_model !== '' ) {
			$openai_model = $fallback_model;
		}
		$payload = [
			'queue_id'        => (int) ( $item->id ?? 0 ),
			'title'           => (string) ( $item->original_title ?? '' ),
			'excerpt'         => (string) ( $item->original_excerpt ?? '' ),
			'content'         => $body_source,
			'url'             => (string) ( $item->original_url ?? '' ),
			'source_name'     => self::source_name_from_dossier( $dossier, $item ),
			'language_hint'   => (string) ( $item->source_language ?? '' ),
			'category_bias'   => (string) ( $item->category_proposed ?? $item->category_final ?? '' ),
			'openai_api_key'  => self::ai_key( 'openai' ),
			'deepseek_api_key' => self::ai_key( 'deepseek' ),
			'ai_provider'     => $ai_provider,
			'ai_model'        => $ai_model,
			'ai_fallback_provider' => $fallback_provider,
			'ai_fallback_model' => $fallback_model,
			'openai_model'    => $openai_model,
			'worker_token'    => self::worker_token(),
		];

		$response = wp_remote_post( self::endpoint_url(), [
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => [
				'Content-Type'         => 'application/json',
				'X-EPV2-Worker-Token'  => self::worker_token(),
			],
			'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		] );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'EPV2_Worker_Client' ) ) {
				EPV2_Worker_Client::invalidate_availability_cache();
			}
			self::log_warn( 'wp_remote_post failed', [ 'error' => $response->get_error_message() ] );
			return self::empty_card( 'transport: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			if ( class_exists( 'EPV2_Worker_Client' ) ) {
				EPV2_Worker_Client::invalidate_availability_cache();
			}
			self::log_warn( 'non-2xx from worker', [ 'code' => $code, 'body' => substr( $raw_body, 0, 200 ) ] );
			return self::empty_card( 'http_' . $code );
		}
		try {
			$decoded = json_decode( $raw_body, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			self::log_warn( 'json_decode failed', [ 'error' => $e->getMessage() ] );
			return self::empty_card( 'json_decode: ' . $e->getMessage() );
		}
		$card = is_array( $decoded['card'] ?? null ) ? $decoded['card'] : [];
		if ( empty( $card ) ) {
			return self::empty_card( 'empty_card_from_worker' );
		}
		// Stamp the wall-clock so consumers can age the card if they want to
		// rebuild it after a long sleep.
		$card['built_at'] = gmdate( 'Y-m-d H:i:s' );
		$card['prompt_version'] = self::STORY_CARD_PROMPT_VERSION;
		// Phase 1 (2026-05-12): semantic embedding piggy-backed on
		// /analyze_story call. Worker возвращает в response.embedding
		// если OpenAI key есть и call успешен. PHP сохраняет в card
		// под отдельным ключом — downstream может read'ить через
		// from_payload + payload._meta.story_card.semantic_embedding.
		$embedding = is_array( $decoded['embedding'] ?? null ) ? $decoded['embedding'] : [];
		if ( ! empty( $embedding ) && is_array( $embedding['vector'] ?? null ) ) {
			$card['semantic_embedding'] = [
				'model' => (string) ( $embedding['model'] ?? '' ),
				'dim'   => (int) ( $embedding['dim'] ?? 0 ),
				'vector' => $embedding['vector'],
				'input_chars' => (int) ( $embedding['input_chars'] ?? 0 ),
				'tokens' => (int) ( $embedding['tokens'] ?? 0 ),
				'computed_at' => gmdate( 'Y-m-d H:i:s' ),
			];
		}
		return $card;
	}

	/**
	 * Build Story Card на ingest stage — когда у нас ещё нет queue row,
	 * только array $item с original_title/url/excerpt/content/category.
	 * Используется collector'ом для smart event-signature dedup ДО save.
	 */
	public static function build_from_array( array $item, array $dossier = [] ): array {
		$obj = (object) [
			'id'                 => (int) ( $item['id'] ?? 0 ),
			'original_title'     => (string) ( $item['title'] ?? '' ),
			'original_excerpt'   => (string) ( $item['excerpt'] ?? '' ),
			'original_content'   => (string) ( $item['content'] ?? '' ),
			'original_url'       => (string) ( $item['url'] ?? '' ),
			'source_language'    => (string) ( $item['language'] ?? '' ),
			'category_proposed'  => (string) ( $item['category'] ?? '' ),
		];
		return self::build( $obj, $dossier );
	}

	/**
	 * Convenience accessor: read the story card from a payload, normalised.
	 */
	public static function from_payload( array $payload ): array {
		$card = $payload['_meta']['story_card'] ?? null;
		return is_array( $card ) ? $card : [];
	}

	/**
	 * Persist a story card into a payload (does not write to DB; caller
	 * stores the resulting payload).
	 *
	 * 2026-05-14 R4: дополнительно appends entry в `_meta.ai_runtime[]` со
	 * stage='analyze_story' для дневного token-accounting (был shadow spend
	 * ~15% — analyze_story tokens не попадали в EPV2_Stats). Idempotent —
	 * если запись уже есть, не дублируется.
	 */
	public static function attach_to_payload( array $payload, array $card ): array {
		$payload['_meta'] = is_array( $payload['_meta'] ?? null ) ? $payload['_meta'] : [];
		$payload['_meta']['story_card'] = $card;
		// R4 token accounting — emit ai_runtime entry on first attach with tokens.
		$tokens = (int) ( $card['tokens'] ?? 0 );
		if ( $tokens > 0 ) {
			$runtime = is_array( $payload['_meta']['ai_runtime'] ?? null ) ? $payload['_meta']['ai_runtime'] : [];
			$already_recorded = false;
			foreach ( $runtime as $entry ) {
				if ( is_array( $entry ) && ( $entry['stage'] ?? '' ) === 'analyze_story' ) {
					$already_recorded = true;
					break;
				}
			}
			if ( ! $already_recorded ) {
				$runtime[] = [
					'stage'         => 'analyze_story',
					'provider'      => (string) ( $card['provider'] ?? '' ),
					'model'         => (string) ( $card['model'] ?? '' ),
					'tokens'        => $tokens,
					'cached_tokens' => (int) ( $card['cached_tokens'] ?? 0 ),
				];
				$payload['_meta']['ai_runtime'] = $runtime;
			}
		}
		return $payload;
	}

	/**
	 * Confidence gate: should downstream stages trust the card's category?
	 */
	public static function category_is_trusted( array $card, float $min_confidence = 0.6 ): bool {
		if ( empty( $card['success'] ) && empty( $card['v'] ) ) {
			return false;
		}
		$primary = (string) ( $card['category']['primary'] ?? '' );
		if ( $primary === '' ) {
			return false;
		}
		$confidence = (float) ( $card['category']['confidence'] ?? 0.0 );
		return $confidence >= $min_confidence;
	}

	private static function worker_available(): bool {
		if ( ! class_exists( 'EPV2_Worker_Client' ) ) {
			return false;
		}
		if ( ! EPV2_Worker_Client::enabled() ) {
			return false;
		}
		return EPV2_Worker_Client::is_available();
	}

	private static function endpoint_url(): string {
		// EPV2_Worker_Client exposes the base URL via reflection-style settings;
		// for v1 we hard-bind to the local socket the systemd unit always uses.
		return rtrim( (string) ( EPV2_Settings::get( 'worker_url', 'http://127.0.0.1:8765' ) ?: 'http://127.0.0.1:8765' ), '/' )
			. self::ENDPOINT_PATH;
	}

	private static function ai_key( string $provider ): string {
		$keys = EPV2_Settings::get_all()['ai_keys'] ?? [];
		return (string) ( $keys[ $provider ] ?? '' );
	}

	private static function worker_token(): string {
		return (string) ( EPV2_Settings::worker_shared_secret() ?? '' );
	}

	private static function pick_body_text( object $item, array $dossier ): string {
		$dossier_primary = $dossier['primary'] ?? null;
		if ( is_array( $dossier_primary ) ) {
			$primary_content = (string) ( $dossier_primary['content'] ?? '' );
			if ( mb_strlen( $primary_content ) >= 600 ) {
				return $primary_content;
			}
			$primary_excerpt = (string) ( $dossier_primary['excerpt'] ?? '' );
			if ( mb_strlen( $primary_excerpt ) > mb_strlen( (string) ( $item->original_content ?? '' ) ) ) {
				return $primary_excerpt;
			}
		}
		return (string) ( $item->original_content ?? '' );
	}

	private static function source_name_from_dossier( array $dossier, object $item ): string {
		$primary = $dossier['primary'] ?? [];
		if ( is_array( $primary ) && ! empty( $primary['source_name'] ) ) {
			return (string) $primary['source_name'];
		}
		$host = '';
		$candidate_url = (string) ( $item->original_url ?? '' );
		if ( $candidate_url !== '' ) {
			$host = (string) wp_parse_url( $candidate_url, PHP_URL_HOST );
		}
		return $host;
	}

	private static function empty_card( string $reason ): array {
		return [
			'v'        => 1,
			'success'  => false,
			'error'    => $reason,
			'category' => [ 'primary' => '', 'confidence' => 0.0, 'rationale' => '' ],
			'tags'     => [],
			'built_at' => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	private static function log_warn( string $message, array $context = [] ): void {
		if ( class_exists( 'EPV2_Logger' ) ) {
			EPV2_Logger::warning( 'story_card', $message, $context );
		}
	}
}
