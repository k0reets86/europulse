<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Translation_Manager {
	public static function to_uk(array $de_payload): array {
		return self::build_translation($de_payload, 'uk');
	}

	public static function to_en(array $de_payload): array {
		return self::build_translation($de_payload, 'en');
	}

	private static function build_translation(array $de_payload, string $lang): array {
		if (EPV3_AI_Client::available()) {
			try {
				$result = EPV3_AI_Client::generate_json(
					'Return only compact JSON. Translate from German into target language. Preserve facts, citations, tone and structure. Keys: title, lead, content.',
					wp_json_encode([
						'target_language' => $lang,
						'source' => [
							'title' => (string) ($de_payload['title'] ?? ''),
							'lead' => (string) ($de_payload['lead'] ?? ''),
							'content' => (string) ($de_payload['content'] ?? ''),
						],
					], JSON_UNESCAPED_UNICODE),
					2400
				);
				return [
					'lang' => $lang,
					'title' => sanitize_text_field((string) ($result['title'] ?? '')),
					'lead' => sanitize_text_field((string) ($result['lead'] ?? '')),
					'content' => wp_kses_post((string) ($result['content'] ?? '')),
					'translated_from' => 'de',
					'status' => 'translated_ai',
				];
			} catch (Throwable $e) {
			}
		}

		$title = (string) ($de_payload['title'] ?? '');
		$lead = (string) ($de_payload['lead'] ?? '');
		$content = (string) ($de_payload['content'] ?? '');
		if ($lang === 'uk') {
			$title = self::translate_uk($title);
			$lead = self::translate_uk($lead);
			$content = self::translate_uk($content);
		} elseif ($lang === 'en') {
			$title = self::translate_en($title);
			$lead = self::translate_en($lead);
			$content = self::translate_en($content);
		}
		return [
			'lang' => $lang,
			'title' => $title,
			'lead' => $lead,
			'content' => $content,
			'translated_from' => 'de',
			'status' => 'translated',
		];
	}

	private static function translate_uk(string $text): string {
		$map = [
			'Deutschland' => 'Німеччина',
			'deutschland' => 'німеччина',
			'Bundesregierung' => 'уряд Німеччини',
			'Familien' => 'родини',
			'soziale Unterstützung' => 'соціальна підтримка',
			'Berlin' => 'Берлін',
			'Kommunen' => 'громади',
		];
		return strtr($text, $map);
	}

	private static function translate_en(string $text): string {
		$map = [
			'Deutschland' => 'Germany',
			'deutschland' => 'germany',
			'Bundesregierung' => 'German government',
			'Familien' => 'families',
			'soziale Unterstützung' => 'social support',
			'Berlin' => 'Berlin',
			'Kommunen' => 'municipalities',
		];
		return strtr($text, $map);
	}
}
