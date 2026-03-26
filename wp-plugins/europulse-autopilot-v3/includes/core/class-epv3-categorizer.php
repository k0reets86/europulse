<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Categorizer {
	public static function detect(string $title, string $content, string $seed = ''): string {
		$text = mb_strtolower(wp_strip_all_tags($title . ' ' . $content));
		$map = [
			'leben-in-deutschland' => [
				'bürgergeld', 'jobcenter', 'aufenthalt', 'aufenthaltstitel', 'einbürgerung',
				'wohngeld', 'kindergeld', 'arbeitsagentur', 'bamf', 'integration', 'deutschland',
				'familien', 'kitas', 'schule', 'schulen', 'soziale dienste', 'haushalte'
			],
			'politik' => [
				'regierung', 'bundestag', 'kanzler', 'minister', 'wahl', 'gesetz', 'parlament',
				'ukraine', 'russland', 'nato', 'eu', 'bundesregierung'
			],
			'wirtschaft' => [
				'wirtschaft', 'inflation', 'markt', 'investor', 'finanz', 'börse', 'dax',
				'preise', 'energiepreis', 'gaspreis', 'paypal'
			],
			'community' => [
				'community', 'gemeinde', 'verein', 'initiative', 'workshop', 'beratung',
				'festival', 'veranstaltung', 'diaspora', 'begegnung'
			],
			'sport' => [
				'sport', 'liga', 'spiel', 'trainer', 'tor', 'verein', 'fußball', 'bundesliga',
				'match', 'team'
			],
			'kultur' => [
				'kultur', 'festival', 'theater', 'museum', 'konzert', 'ausstellung', 'film', 'musik'
			],
			'world' => [
				'usa', 'china', 'washington', 'gaza', 'israel', 'iran', 'syrien', 'taiwan', 'india'
			],
		];

		$scores = [];
		foreach ($map as $slug => $keywords) {
			$scores[$slug] = 0;
			foreach ($keywords as $keyword) {
				if (str_contains($text, $keyword)) {
					$scores[$slug] += self::keyword_weight($slug, $keyword);
				}
			}
		}

		if ($seed !== '' && isset($scores[$seed])) {
			$scores[$seed] += 1;
		}

		arsort($scores);
		$category = (string) array_key_first($scores);
		return ($scores[$category] ?? 0) > 0 ? $category : ($seed !== '' ? $seed : 'news');
	}

	private static function keyword_weight(string $category, string $keyword): int {
		if ($category === 'leben-in-deutschland' && in_array($keyword, ['familien', 'kitas', 'schule', 'schulen', 'soziale dienste', 'haushalte', 'kindergeld', 'wohngeld'], true)) {
			return 2;
		}

		if ($category === 'politik' && in_array($keyword, ['regierung', 'bundestag', 'kanzler', 'minister', 'wahl', 'gesetz', 'parlament'], true)) {
			return 2;
		}

		return 1;
	}
}
