<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Site_Profile {
	private static ?array $profile = null;

	public static function get(): array {
		if (self::$profile !== null) {
			return self::$profile;
		}

		$json = get_option('europulse_ui_system_profile_json', '');
		$data = json_decode((string) $json, true);
		self::$profile = is_array($data) ? $data : [];
		return self::$profile;
	}

	public static function get_value(string $path, $default = null) {
		$data = self::get();
		$parts = explode('.', $path);
		foreach ($parts as $part) {
			if (! is_array($data) || ! array_key_exists($part, $data)) {
				return $default;
			}
			$data = $data[$part];
		}
		return $data;
	}

	public static function get_languages(): array {
		return self::get_value('site.languages', ['de', 'uk', 'en']);
	}

	public static function text_budget(string $lang = 'de', string $zone = 'news', string $category = 'deutschland', array $context = []): array {
		$lang = in_array($lang, ['de', 'uk', 'en'], true) ? $lang : 'de';
		$shape = self::story_shape($zone, $category, $context);
		$defaults = [
			'title_chars' => [
				'de' => 56,
				'uk' => 48,
				'en' => 58,
			],
			'lead_chars' => [
				'de' => 168,
				'uk' => 148,
				'en' => 166,
			],
			'card_excerpt_chars' => [
				'de' => 88,
				'uk' => 72,
				'en' => 88,
			],
		];
		$ceilings = [
			'title_chars' => [
				'de' => 56,
				'uk' => 48,
				'en' => 58,
			],
			'lead_chars' => [
				'de' => 168,
				'uk' => 148,
				'en' => 166,
			],
			'card_excerpt_chars' => [
				'de' => 88,
				'uk' => 72,
				'en' => 88,
			],
		];

		$slider_excerpt = self::get_value('slider_rules.excerpt_char_limits.' . $lang, $defaults['lead_chars'][$lang]);
		$latest_excerpt = self::get_value('latest_rules.excerpt_char_limits.' . $lang, $defaults['card_excerpt_chars'][$lang]);
		$title_chars = self::get_value('text_budgets.title_chars.' . $lang, $defaults['title_chars'][$lang]);
		$title_chars = min((int) $title_chars, (int) $ceilings['title_chars'][$lang]);
		$slider_excerpt = min((int) $slider_excerpt, (int) $ceilings['lead_chars'][$lang]);
		$latest_excerpt = min((int) $latest_excerpt, (int) $ceilings['card_excerpt_chars'][$lang]);

		$shape_profiles = [
			'bulletin' => [
				'title' => ['de' => 52, 'uk' => 44, 'en' => 54],
				'lead' => ['de' => 112, 'uk' => 102, 'en' => 116],
				'card' => ['de' => 72, 'uk' => 64, 'en' => 74],
				'content_min' => ['de' => 220, 'uk' => 180, 'en' => 180],
				'content_soft' => ['de' => 420, 'uk' => 320, 'en' => 320],
				'content_target' => ['de' => 680, 'uk' => 520, 'en' => 520],
			],
			'service_note' => [
				'title' => ['de' => 56, 'uk' => 48, 'en' => 58],
				'lead' => ['de' => 142, 'uk' => 128, 'en' => 144],
				'card' => ['de' => 82, 'uk' => 70, 'en' => 82],
				'content_min' => ['de' => 420, 'uk' => 300, 'en' => 300],
				'content_soft' => ['de' => 800, 'uk' => 580, 'en' => 580],
				'content_target' => ['de' => 1180, 'uk' => 860, 'en' => 860],
			],
			'preview' => [
				'title' => ['de' => 56, 'uk' => 48, 'en' => 58],
				'lead' => ['de' => 152, 'uk' => 134, 'en' => 154],
				'card' => ['de' => 82, 'uk' => 72, 'en' => 82],
				'content_min' => ['de' => 640, 'uk' => 500, 'en' => 500],
				'content_soft' => ['de' => 1100, 'uk' => 820, 'en' => 820],
				'content_target' => ['de' => 1500, 'uk' => 1120, 'en' => 1120],
			],
			'news' => [
				'title' => $defaults['title_chars'],
				'lead' => $defaults['lead_chars'],
				'card' => $defaults['card_excerpt_chars'],
				'content_min' => ['de' => 700, 'uk' => 520, 'en' => 520],
				'content_soft' => ['de' => 1100, 'uk' => 800, 'en' => 800],
				'content_target' => ['de' => 1500, 'uk' => 1100, 'en' => 1100],
			],
			'article' => [
				'title' => ['de' => 58, 'uk' => 50, 'en' => 60],
				'lead' => ['de' => 168, 'uk' => 146, 'en' => 168],
				'card' => ['de' => 88, 'uk' => 74, 'en' => 88],
				'content_min' => ['de' => 980, 'uk' => 740, 'en' => 740],
				'content_soft' => ['de' => 1450, 'uk' => 1040, 'en' => 1040],
				'content_target' => ['de' => 1900, 'uk' => 1380, 'en' => 1380],
			],
			'developing' => [
				'title' => ['de' => 58, 'uk' => 50, 'en' => 60],
				'lead' => ['de' => 170, 'uk' => 150, 'en' => 170],
				'card' => ['de' => 88, 'uk' => 74, 'en' => 88],
				'content_min' => ['de' => 1100, 'uk' => 850, 'en' => 850],
				'content_soft' => ['de' => 1600, 'uk' => 1200, 'en' => 1200],
				'content_target' => ['de' => 2200, 'uk' => 1680, 'en' => 1680],
			],
			'analysis' => [
				'title' => ['de' => 60, 'uk' => 52, 'en' => 62],
				'lead' => ['de' => 180, 'uk' => 160, 'en' => 180],
				'card' => ['de' => 92, 'uk' => 78, 'en' => 92],
				'content_min' => ['de' => 1600, 'uk' => 1280, 'en' => 1280],
				'content_soft' => ['de' => 2200, 'uk' => 1760, 'en' => 1760],
				'content_target' => ['de' => 2800, 'uk' => 2240, 'en' => 2240],
			],
		];
		$shape_profile = $shape_profiles[$shape] ?? $shape_profiles['news'];
		$title_chars = min((int) $shape_profile['title'][$lang], (int) $ceilings['title_chars'][$lang]);
		$lead_base = (int) $shape_profile['lead'][$lang];
		$card_base = (int) $shape_profile['card'][$lang];
		$lead_chars = match ($zone) {
			'latest' => min($card_base, (int) $ceilings['card_excerpt_chars'][$lang]),
			'slider', 'news', 'developing' => min($lead_base, (int) $ceilings['lead_chars'][$lang]),
			'analysis' => min(max($lead_base, (int) $slider_excerpt), max((int) $ceilings['lead_chars'][$lang], $lead_base)),
			default => min($lead_base, (int) $ceilings['lead_chars'][$lang]),
		};

		return [
			'title_chars' => (int) $title_chars,
			'lead_chars' => (int) $lead_chars,
			'card_excerpt_chars' => min($card_base, (int) $ceilings['card_excerpt_chars'][$lang]),
			'slider_excerpt_chars' => (int) $slider_excerpt,
			'content_min_chars' => (int) ($shape_profile['content_min'][$lang] ?? 700),
			'content_soft_chars' => (int) ($shape_profile['content_soft'][$lang] ?? 1100),
			'content_target_chars' => (int) ($shape_profile['content_target'][$lang] ?? 1500),
			'shape' => $shape,
		];
	}

	private static function story_shape(string $zone, string $category, array $context = []): string {
		$zone = sanitize_key($zone);
		$category = sanitize_key($category);
		$event_kind = sanitize_key((string) ($context['event_kind'] ?? ''));
		$source_count = max(1, (int) ($context['source_count'] ?? 1));
		$text = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($context['title'] ?? ''),
			(string) ($context['excerpt'] ?? ''),
			wp_strip_all_tags((string) ($context['content'] ?? '')),
			(string) ($context['datetime_text'] ?? ''),
			(string) ($context['venue'] ?? ''),
			(string) ($context['stage'] ?? ''),
		]))));

		if ($zone === 'analysis') {
			return 'analysis';
		}
		if ($zone === 'developing') {
			return 'developing';
		}
		if (
			$event_kind === 'sport'
			&& preg_match('/\b(vor dem|preview|rückspiel|rueckspiel|anpfiff|kickoff|stadion|halbfinale|viertelfinale|achtelfinale|referee|schiedsrichter)\b/u', $text) === 1
		) {
			return 'preview';
		}
		if (
			in_array($category, ['community', 'leben-in-deutschland', 'deutschland', 'bayern', 'münchen'], true)
			&& preg_match('/\b(streik|warnung|hinweis|morgen|heute|ab morgen|fällt aus|fallen aus|ausfall|sperrung|umleitung|beratung|sprechstunde|anmeldung|frist|jobmesse|karrieremesse|bildungsmesse|termin|infoabend|veranstaltung)\b/u', $text) === 1
		) {
			return 'service_note';
		}
		if (
			$source_count <= 1
			&& mb_strlen($text) <= 420
			&& preg_match('/\b(heute|morgen|wird|soll|startet|beginnt|endet|streik|warnung|show|sendung|spiel|duell|event|messe|börse|beratung)\b/u', $text) === 1
		) {
			return 'bulletin';
		}
		if ($source_count >= 3 || in_array($category, ['politik', 'wirtschaft', 'world', 'europa'], true)) {
			return 'article';
		}
		return 'news';
	}
}
