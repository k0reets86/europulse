<?php
/**
 * EuroPulse AutoPilot v2.1 — Schema.org NewsArticle enricher.
 *
 * Hooks into Rank Math SEO's `rank_math/json_ld` filter and augments the
 * NewsArticle node with fields that matter for Google News, AI search
 * crawlers (Perplexity, ChatGPT, Claude) and LLM-based answer engines:
 *
 *   - articleBody       : full plain-text body of the article so LLMs
 *                         can ingest the canonical version without
 *                         scraping the page DOM.
 *   - keywords          : merged story_card.tags + WP post tags.
 *   - mentions          : array of Person / Organization / Place entities
 *                         lifted from `_meta.story_card.entities_*`.
 *   - about             : Thing entities derived from story_card.topics.
 *   - wordCount         : numeric.
 *   - isAccessibleForFree : true (Google News signal).
 *   - speakable         : SpeakableSpecification pointing at the lead.
 *   - thumbnailUrl      : extra image hint for Google Discover.
 *
 * The data source is the queue row's `_meta.story_card` (built once
 * upfront by EPV2_Story_Card_Builder). When the card is missing the
 * enricher only adds what is computable from the post itself
 * (articleBody, wordCount, post-tag keywords, isAccessibleForFree,
 * speakable).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EPV2_Schema_Enricher {

	public static function register(): void {
		add_filter( 'rank_math/json_ld', [ self::class, 'augment_json_ld' ], 20, 2 );
	}

	/**
	 * Rank Math passes us $data (an associative graph keyed by Schema id)
	 * and a JsonLD object. We mutate the NewsArticle node in place.
	 *
	 * @param array $data
	 * @param mixed $jsonld
	 * @return array
	 */
	public static function augment_json_ld( $data, $jsonld ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( ! is_singular( 'post' ) ) {
			return $data;
		}
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || $post->post_type !== 'post' ) {
			return $data;
		}
		$article_node_key = self::find_article_node_key( $data );
		if ( $article_node_key === '' ) {
			return $data;
		}
		$node = is_array( $data[ $article_node_key ] ) ? $data[ $article_node_key ] : [];

		$plain_body = self::plain_body( $post );
		$word_count = $plain_body !== '' ? str_word_count( $plain_body ) : 0;
		if ( $word_count > 0 ) {
			$node['wordCount'] = $word_count;
		}
		if ( $plain_body !== '' ) {
			// Cap at 30 KB to keep the JSON-LD blob reasonable on long
			// analysis pieces; LLM crawlers care more about the start of
			// the body than the tail.
			$node['articleBody'] = self::trim_text( $plain_body, 30000 );
		}
		$node['isAccessibleForFree'] = true;
		$lead_xpath = "/html/head/title|/html/body//h1|/html/body//p[1]";
		$node['speakable'] = [
			'@type'    => 'SpeakableSpecification',
			'xpath'    => [ $lead_xpath ],
			'cssSelector' => [ 'article h1', 'article p:first-of-type', '.entry-content > p:first-of-type' ],
		];

		$story_card = self::story_card_for_post( (int) $post->ID );
		$post_tag_names = self::post_tag_names( (int) $post->ID );

		// Keywords: prefer card.tags, then merge with WP post tags.
		$keywords = [];
		if ( $story_card !== [] ) {
			$card_tags = (array) ( $story_card['tags'] ?? [] );
			foreach ( $card_tags as $t ) {
				$t = trim( (string) $t );
				if ( $t !== '' ) {
					$keywords[] = $t;
				}
			}
		}
		foreach ( $post_tag_names as $name ) {
			if ( ! in_array( $name, $keywords, true ) ) {
				$keywords[] = $name;
			}
		}
		if ( $keywords !== [] ) {
			$node['keywords'] = implode( ', ', array_slice( $keywords, 0, 12 ) );
		}

		if ( $story_card !== [] ) {
			$mentions = self::build_mentions( $story_card );
			if ( $mentions !== [] ) {
				$node['mentions'] = $mentions;
			}
			$about = self::build_about( $story_card );
			if ( $about !== [] ) {
				$node['about'] = $about;
			}
			// Geographic dateline-style hint for news pieces.
			$geo = is_array( $story_card['geography'] ?? null ) ? $story_card['geography'] : [];
			$primary_country = strtoupper( trim( (string) ( $geo['primary_country'] ?? '' ) ) );
			if ( $primary_country !== '' ) {
				$node['contentLocation'] = [
					'@type'              => 'Place',
					'name'               => $primary_country,
					'addressCountry'     => $primary_country,
				];
			}
			// Supplement description with story-card rationale when Rank
			// Math left it empty.
			if ( empty( $node['description'] ) ) {
				$rationale = trim( (string) ( $story_card['category']['rationale'] ?? '' ) );
				if ( $rationale !== '' ) {
					$node['description'] = $rationale;
				}
			}
		}

		// thumbnailUrl helps Google Discover match the right preview.
		if ( has_post_thumbnail( $post ) ) {
			$thumb_url = get_the_post_thumbnail_url( $post, 'large' );
			if ( $thumb_url ) {
				$node['thumbnailUrl'] = $thumb_url;
			}
		}

		$data[ $article_node_key ] = $node;
		return $data;
	}

	private static function find_article_node_key( array $data ): string {
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = $node['@type'] ?? '';
			if ( is_array( $type ) ) {
				if ( in_array( 'NewsArticle', $type, true ) || in_array( 'Article', $type, true ) ) {
					return (string) $key;
				}
				continue;
			}
			if ( $type === 'NewsArticle' || $type === 'Article' ) {
				return (string) $key;
			}
		}
		return '';
	}

	private static function plain_body( WP_Post $post ): string {
		$content = (string) apply_filters( 'the_content', $post->post_content );
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/u', ' ', $content );
		return trim( (string) $content );
	}

	private static function trim_text( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $limit );
		$space = mb_strrpos( $cut, ' ' );
		if ( $space !== false ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut );
	}

	private static function post_tag_names( int $post_id ): array {
		$tags = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
		return is_array( $tags ) ? array_values( array_filter( array_map( 'trim', $tags ) ) ) : [];
	}

	private static function story_card_for_post( int $post_id ): array {
		$queue_id = (int) get_post_meta( $post_id, '_epv2_queue_id', true );
		if ( $queue_id <= 0 ) {
			return [];
		}
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ai_payload FROM {$table} WHERE id = %d", $queue_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return [];
		}
		$payload = json_decode( (string) ( $row['ai_payload'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			return [];
		}
		$card = $payload['_meta']['story_card'] ?? null;
		return is_array( $card ) ? $card : [];
	}

	private static function build_mentions( array $story_card ): array {
		$mentions = [];
		$people = (array) ( $story_card['entities_people'] ?? $story_card['entities']['people'] ?? [] );
		foreach ( array_slice( $people, 0, 8 ) as $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}
			$name = trim( (string) ( $person['name'] ?? '' ) );
			if ( $name === '' ) {
				continue;
			}
			$entry = [
				'@type' => 'Person',
				'name'  => $name,
			];
			$role = trim( (string) ( $person['role'] ?? '' ) );
			if ( $role !== '' ) {
				$entry['jobTitle'] = $role;
			}
			$mentions[] = $entry;
		}
		$orgs = (array) ( $story_card['entities_organizations'] ?? $story_card['entities']['organizations'] ?? [] );
		foreach ( array_slice( $orgs, 0, 6 ) as $org ) {
			if ( ! is_array( $org ) ) {
				continue;
			}
			$name = trim( (string) ( $org['name'] ?? '' ) );
			if ( $name === '' ) {
				continue;
			}
			$entry = [
				'@type' => 'Organization',
				'name'  => $name,
			];
			$kind = trim( (string) ( $org['kind'] ?? '' ) );
			if ( $kind !== '' ) {
				$entry['additionalType'] = $kind;
			}
			$mentions[] = $entry;
		}
		$places = (array) ( $story_card['entities_places'] ?? $story_card['entities']['places'] ?? [] );
		foreach ( array_slice( $places, 0, 8 ) as $place ) {
			$name = trim( (string) $place );
			if ( $name === '' ) {
				continue;
			}
			$mentions[] = [
				'@type' => 'Place',
				'name'  => $name,
			];
		}
		return $mentions;
	}

	private static function build_about( array $story_card ): array {
		$topics = (array) ( $story_card['topics'] ?? [] );
		$about = [];
		foreach ( array_slice( $topics, 0, 6 ) as $topic ) {
			$name = trim( (string) $topic );
			if ( $name === '' ) {
				continue;
			}
			$about[] = [
				'@type' => 'Thing',
				'name'  => $name,
			];
		}
		return $about;
	}
}
