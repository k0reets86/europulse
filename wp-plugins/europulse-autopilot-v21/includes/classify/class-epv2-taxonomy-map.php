<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Taxonomy_Map {
	public static function categories(): array {
		return [
			'deutschland' => 'Германия',
			'muenchen' => 'Мюнхен',
			'bayern' => 'Бавария',
			'ukraine' => 'Украина',
			'europa' => 'Европа',
			'welt' => 'Мир',
			'politik' => 'Политика',
			'wirtschaft' => 'Экономика',
			'auto' => 'Авто',
			'it' => 'IT',
			'technologie' => 'Технологии',
			'leben-in-deutschland' => 'Жизнь в Германии',
			'kultur' => 'Культура',
			'sport' => 'Спорт',
			'meinung' => 'Мнение',
			'community' => 'Комьюнити',
			'veranstaltungen' => 'События',
			'ukrainische-initiativen' => 'Украинские инициативы',
			'vereine-projekte' => 'Объединения и проекты',
			'treffen-networking' => 'Встречи и нетворкинг',
		];
	}

	public static function map(string $slug, string $lang = 'de'): array {
		$profile = EPV2_Site_Profile::get();
		$slug = self::normalize_slug($slug);
		$map = [
			'de' => ['deutschland' => 14, 'muenchen' => 1, 'bayern' => 12, 'ukraine' => 1140, 'europa' => 18, 'welt' => 2801, 'politik' => 22, 'wirtschaft' => 24, 'auto' => 31377, 'it' => 31383, 'technologie' => 31389, 'leben-in-deutschland' => 26, 'kultur' => 28, 'sport' => 249, 'meinung' => 31395, 'community' => 1258, 'veranstaltungen' => 48, 'ukrainische-initiativen' => 50, 'vereine-projekte' => 52, 'treffen-networking' => 54],
			'uk' => ['deutschland' => 184, 'muenchen' => 187, 'bayern' => 189, 'ukraine' => 191, 'europa' => 194, 'welt' => 2805, 'politik' => 197, 'wirtschaft' => 200, 'auto' => 31381, 'it' => 31387, 'technologie' => 31393, 'leben-in-deutschland' => 203, 'kultur' => 206, 'sport' => 209, 'meinung' => 31400, 'community' => 212, 'veranstaltungen' => 215, 'ukrainische-initiativen' => 217, 'vereine-projekte' => 219, 'treffen-networking' => 221],
			'en' => ['deutschland' => 151, 'muenchen' => 154, 'bayern' => 156, 'ukraine' => 16, 'europa' => 159, 'welt' => 2803, 'politik' => 162, 'wirtschaft' => 165, 'auto' => 31379, 'it' => 31385, 'technologie' => 31391, 'leben-in-deutschland' => 168, 'kultur' => 171, 'sport' => 30, 'meinung' => 31397, 'community' => 46, 'veranstaltungen' => 176, 'ukrainische-initiativen' => 178, 'vereine-projekte' => 180, 'treffen-networking' => 182],
		];
		$id = $map[$lang][$slug] ?? 0;
		return ['term_id' => $id, 'lang' => $lang, 'slug' => $slug, 'profile' => $profile ? 'loaded' : 'none'];
	}

	public static function canonical_slug_for_term_id(int $term_id): string {
		foreach (['de', 'uk', 'en'] as $lang) {
			foreach (self::categories() as $slug => $label) {
				$mapped = self::map($slug, $lang);
				if ((int) ($mapped['term_id'] ?? 0) === $term_id) {
					return (string) $slug;
				}
			}
		}

		$term = get_term($term_id, 'category');
		if ($term && ! is_wp_error($term)) {
			$slug = self::normalize_slug((string) ($term->slug ?? ''));
			$slug = preg_replace('/-(de|uk|en)$/i', '', $slug) ?: $slug;
			$slug = self::normalize_slug($slug);
			foreach (array_keys(self::categories()) as $candidate) {
				if ($slug === $candidate) {
					return $candidate;
				}
			}
		}

		return '';
	}

	public static function normalize_slug(string $slug): string {
		$slug = sanitize_title($slug);

		return match ($slug) {
			'münchen', 'munchen', 'munich' => 'muenchen',
			'world' => 'welt',
			'leben_in_deutschland' => 'leben-in-deutschland',
			'ukrainische_initiativen' => 'ukrainische-initiativen',
			'vereine_projekte' => 'vereine-projekte',
			'treffen_networking' => 'treffen-networking',
			'events' => 'veranstaltungen',
			'ukrainian-initiatives' => 'ukrainische-initiativen',
			'associations-projects' => 'vereine-projekte',
			'meetings-networking' => 'treffen-networking',
			default => $slug,
		};
	}
}
