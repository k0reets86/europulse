<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Taxonomy_Map {
	public static function categories(): array {
		return [
			'deutschland' => 'Германия',
			'münchen' => 'Мюнхен',
			'bayern' => 'Бавария',
			'ukraine' => 'Украина',
			'europa' => 'Европа',
			'politik' => 'Политика',
			'wirtschaft' => 'Экономика',
			'leben-in-deutschland' => 'Жизнь в Германии',
			'kultur' => 'Культура',
			'sport' => 'Спорт',
			'community' => 'Комьюнити',
			'world' => 'Мир',
		];
	}

	public static function map(string $slug, string $lang = 'de'): array {
		$profile = EPV2_Site_Profile::get();
		$map = [
			'de' => ['deutschland' => 14, 'münchen' => 1, 'bayern' => 12, 'ukraine' => 16, 'europa' => 18, 'politik' => 22, 'wirtschaft' => 24, 'leben-in-deutschland' => 26, 'kultur' => 28, 'sport' => 249, 'community' => 1258, 'world' => 2801],
			'uk' => ['deutschland' => 184, 'münchen' => 187, 'bayern' => 189, 'ukraine' => 191, 'europa' => 194, 'politik' => 197, 'wirtschaft' => 200, 'leben-in-deutschland' => 203, 'kultur' => 206, 'sport' => 209, 'community' => 212, 'world' => 2805],
			'en' => ['deutschland' => 151, 'münchen' => 154, 'bayern' => 156, 'ukraine' => 16, 'europa' => 159, 'politik' => 162, 'wirtschaft' => 165, 'leben-in-deutschland' => 168, 'kultur' => 171, 'sport' => 30, 'community' => 46, 'world' => 2803],
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
			$slug = (string) ($term->slug ?? '');
			$slug = preg_replace('/-(de|uk|en)$/i', '', $slug) ?: $slug;
			foreach (array_keys(self::categories()) as $candidate) {
				if ($slug === $candidate) {
					return $candidate;
				}
			}
		}

		return '';
	}
}
