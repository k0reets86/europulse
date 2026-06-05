<?php

function europulse_ads_enabled() {
	return (bool) get_option('europulse_ads_enabled', true);
}

function europulse_current_lang(): string {
	if (function_exists('pll_current_language')) {
		$lang = (string) pll_current_language('slug');

		if ($lang !== '') {
			return $lang;
		}
	}

	return 'de';
}

function europulse_public_url(?string $lang = null): string {
	$lang = $lang ?: europulse_current_lang();

	if (function_exists('pll_home_url')) {
		$translated_home = (string) pll_home_url($lang);

		if ($translated_home !== '') {
			return $translated_home;
		}
	}

	if ($lang === 'de') {
		return home_url('/');
	}

	return add_query_arg('lang', $lang, home_url('/'));
}

function europulse_self_canonical_url(): string {
	$lang = europulse_current_lang();

	if (is_front_page()) {
		return europulse_public_url($lang);
	}

	if (is_home()) {
		$posts_page_id = (int) get_option('page_for_posts');
		if ($posts_page_id > 0) {
			$url = get_permalink($posts_page_id);
			if (is_string($url) && $url !== '') {
				return $url;
			}
		}
	}

	$relevant = [];

	foreach (['lang', 'cat', 'paged', 's', 'p', 'page_id'] as $key) {
		if (isset($_GET[$key]) && $_GET[$key] !== '') {
			$relevant[$key] = sanitize_text_field(wp_unslash((string) $_GET[$key]));
		}
	}

	$request_path = trim((string) ($GLOBALS['wp']->request ?? ''), '/');
	$url = $request_path !== '' ? home_url('/' . $request_path . '/') : home_url('/');

	if (! empty($relevant)) {
		$url = add_query_arg($relevant, $url);
	}

	return $url;
}

function europulse_should_force_self_canonical(): bool {
	return is_front_page() || is_home() || is_category() || is_tag() || is_search();
}

add_filter('template_include', function ($template) {
	if (! is_home() || is_front_page()) {
		return $template;
	}

	$custom = WP_CONTENT_DIR . '/mu-plugins/europulse-foundation/templates/home-newsroom.php';

	return file_exists($custom) ? $custom : $template;
}, 20);

function europulse_t(string $key): string {
	$lang = europulse_current_lang();

	$strings = [
		'de' => [
			'breaking' => 'Wichtig',
			'top_story' => 'Top Thema',
			'ad' => 'Anzeige',
			'search' => 'Suche',
			'previous' => 'Zurück',
			'next' => 'Weiter',
			'previous_topic' => 'Vorheriges Thema',
			'next_topic' => 'Nächstes Thema',
			'read_more' => 'Weiterlesen',
			'ad_button_bookmark' => 'Jetzt vormerken',
			'ad_button_contact' => 'Direkt anfragen',
			'ad_button_try' => 'Heute testen',
			'ad_button_demo' => 'Demo starten',
			'ad_button_talk' => 'Kostenfrei sprechen',
			'ad_sidebar_headline' => 'Apartments für Projektteams und Speaker',
			'ad_sidebar_text' => 'Berlin Mitte · Flexible Aufenthalte',
			'ad_sidebar_badge' => 'ab 7 Nächten',
			'ad_note' => 'Dieser Beitrag ist als kommerzieller Inhalt bzw. bezahlte Kooperation klar gekennzeichnet.',
			'advertising_slot' => 'Werbefläche: %s',
			'advertising_demo' => 'Demofläche für Werbung',
			'advertising_label' => 'Anzeige',
			'advertising_sponsored' => 'Gesponserter Inhalt',
			'header_leaderboard' => 'Header Leaderboard',
			'sidebar_rail' => 'Sidebar Rectangle',
			'article_inline' => 'Artikel-Integration',
			'between_sections' => 'Zwischen den Ressorts',
			'discover_more' => 'Mehr erfahren',
			'video' => 'Video',
			'de' => 'DE',
			'uk' => 'UKR',
			'en' => 'EN',
			'city_berlin' => 'Berlin',
			'city_kyiv' => 'Kyjiw',
			'city_london' => 'London',
			'section_empty' => 'Noch keine Artikel in %s.',
			'section_more_soon' => 'Weitere %s-Themen erscheinen hier.',
			'most_read_empty' => 'Die wichtigsten Themen erscheinen hier automatisch.',
			'latest_empty' => 'Die neuesten Themen erscheinen hier automatisch.',
			'home_latest_empty' => 'Die aktuelle Meldungsliste füllt sich automatisch mit den neuesten Veröffentlichungen.',
			'secondary_empty' => 'Hier erscheinen drei sekundäre Geschichten mit klarer Hierarchie und ohne Magazin-Chaos.',
			'analysis_empty' => 'Ausgewählte Analysen und Hintergründe erscheinen hier, sobald passende Beiträge veröffentlicht sind.',
			'slider_empty' => 'Noch keine Leitgeschichten verfügbar.',
			'back_to_top' => 'Nach oben',
			'category_label' => 'Kategorie',
			'no_results' => 'Keine Ergebnisse',
			'slider_navigation' => 'Top-Themen Navigation',
			'slider_topic_aria' => 'Thema %d',
			'important_now' => 'Wichtig im Blick',
			'all_news' => 'Alle Meldungen',
			'today' => 'Heute',
			'earlier' => 'Weitere Meldungen',
		],
		'uk' => [
			'breaking' => 'Важливо',
			'top_story' => 'Головна тема',
			'ad' => 'Реклама',
			'search' => 'Пошук',
			'previous' => 'Назад',
			'next' => 'Далі',
			'previous_topic' => 'Попередня тема',
			'next_topic' => 'Наступна тема',
			'read_more' => 'Докладніше',
			'ad_button_bookmark' => 'Запланувати',
			'ad_button_contact' => 'Запитати зараз',
			'ad_button_try' => 'Спробувати сьогодні',
			'ad_button_demo' => 'Запустити демо',
			'ad_button_talk' => 'Поговорити безкоштовно',
			'ad_sidebar_headline' => 'Апартаменти для команд і спікерів',
			'ad_sidebar_text' => 'Берлін Мітте · Гнучке проживання',
			'ad_sidebar_badge' => 'від 7 ночей',
			'ad_note' => 'Цей матеріал чітко позначено як комерційний контент або оплачена співпраця.',
			'advertising_slot' => 'Рекламний слот: %s',
			'advertising_demo' => 'Демонстраційний рекламний блок',
			'advertising_label' => 'Реклама',
			'advertising_sponsored' => 'Спонсорований матеріал',
			'header_leaderboard' => 'Верхній банер',
			'sidebar_rail' => 'Боковий банер',
			'article_inline' => 'Вставка в статтю',
			'between_sections' => 'Між редакційними блоками',
			'discover_more' => 'Дізнатися більше',
			'video' => 'Відео',
			'de' => 'DE',
			'uk' => 'UKR',
			'en' => 'EN',
			'city_berlin' => 'Берлін',
			'city_kyiv' => 'Київ',
			'city_london' => 'Лондон',
			'section_empty' => 'Поки що немає матеріалів у рубриці %s.',
			'section_more_soon' => 'Інші матеріали рубрики %s з’являться тут.',
			'most_read_empty' => 'Найважливіші теми автоматично зʼявлятимуться тут.',
			'latest_empty' => 'Найновіші теми автоматично зʼявлятимуться тут.',
			'home_latest_empty' => 'Список актуальних новин автоматично наповнюється найновішими публікаціями.',
			'secondary_empty' => 'Тут автоматично зʼявлятимуться додаткові важливі історії.',
			'analysis_empty' => 'Добірні аналітичні матеріали та контекст зʼявляться тут, щойно будуть опубліковані відповідні тексти.',
			'slider_empty' => 'Головні матеріали ще не доступні.',
			'back_to_top' => 'До початку сторінки',
			'category_label' => 'Рубрика',
			'no_results' => 'Немає результатів',
			'slider_navigation' => 'Навігація головними темами',
			'slider_topic_aria' => 'Тема %d',
			'important_now' => 'Важливо зараз',
			'all_news' => 'Усі новини',
			'today' => 'Сьогодні',
			'earlier' => 'Інші новини',
		],
		'en' => [
			'breaking' => 'Important',
			'top_story' => 'Top Story',
			'ad' => 'Advertisement',
			'search' => 'Search',
			'previous' => 'Back',
			'next' => 'Next',
			'previous_topic' => 'Previous story',
			'next_topic' => 'Next story',
			'read_more' => 'Read more',
			'ad_button_bookmark' => 'Save the date',
			'ad_button_contact' => 'Enquire now',
			'ad_button_try' => 'Try it today',
			'ad_button_demo' => 'Start demo',
			'ad_button_talk' => 'Talk for free',
			'ad_sidebar_headline' => 'Apartments for project teams and speakers',
			'ad_sidebar_text' => 'Berlin Mitte · Flexible stays',
			'ad_sidebar_badge' => 'from 7 nights',
			'ad_note' => 'This article is clearly marked as commercial content or paid cooperation.',
			'advertising_slot' => 'Advertising slot: %s',
			'advertising_demo' => 'Demo advertising placement',
			'advertising_label' => 'Advertisement',
			'advertising_sponsored' => 'Sponsored content',
			'header_leaderboard' => 'Header leaderboard',
			'sidebar_rail' => 'Sidebar rectangle',
			'article_inline' => 'In-article placement',
			'between_sections' => 'Between sections',
			'discover_more' => 'Learn more',
			'video' => 'Video',
			'de' => 'DE',
			'uk' => 'UKR',
			'en' => 'EN',
			'city_berlin' => 'Berlin',
			'city_kyiv' => 'Kyiv',
			'city_london' => 'London',
			'section_empty' => 'No articles in %s yet.',
			'section_more_soon' => 'More %s stories will appear here.',
			'most_read_empty' => 'The most important stories will appear here automatically.',
			'latest_empty' => 'The latest stories will appear here automatically.',
			'home_latest_empty' => 'The latest story list fills automatically with the newest publications.',
			'secondary_empty' => 'Secondary stories will appear here automatically.',
			'analysis_empty' => 'Selected analysis and background stories will appear here when matching articles are published.',
			'slider_empty' => 'No lead stories available yet.',
			'back_to_top' => 'Back to top',
			'category_label' => 'Category',
			'no_results' => 'No results',
			'slider_navigation' => 'Top stories navigation',
			'slider_topic_aria' => 'Story %d',
			'important_now' => 'Important Now',
			'all_news' => 'All News',
			'today' => 'Today',
			'earlier' => 'Earlier News',
		],
	];

	return $strings[$lang][$key] ?? $strings['de'][$key] ?? $key;
}

function europulse_lang_label(string $slug): string {
	return europulse_t($slug);
}

function europulse_media_credit_prefix(?string $lang = null): string {
	$lang = $lang ?: europulse_current_lang();
	return match ($lang) {
		'uk' => 'Фото',
		'en' => 'Photo',
		default => 'Bild',
	};
}

function europulse_source_label_from_url(string $url): string {
	$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
	$host = preg_replace('/^www\./', '', $host);
	if ($host === '') {
		return '';
	}
	$map = [
		'pexels.com' => 'Pexels',
		'images.pexels.com' => 'Pexels',
		'cbc.ca' => 'CBC News',
		'i.cbc.ca' => 'CBC News',
		'commons.wikimedia.org' => 'Wikimedia Commons',
		'upload.wikimedia.org' => 'Wikimedia Commons',
		'br.de' => 'BR',
		'img.br.de' => 'BR',
		's.hs-data.com' => 'Sportdaten',
		'bundesregierung.de' => 'Bundesregierung',
		'bamf.de' => 'BAMF',
		'arbeitsagentur.de' => 'Bundesagentur für Arbeit',
		'bundestag.de' => 'Deutscher Bundestag',
		'tagesschau.de' => 'Tagesschau',
		'images.tagesschau.de' => 'Tagesschau',
		'stmi.bayern.de' => 'Bayerisches Innenministerium',
		'bayern.de' => 'Bayern.de',
		'muenchen.de' => 'muenchen.de',
		'stadt.muenchen.de' => 'Stadt Muenchen',
		'news.google.com' => 'Google News',
	];
	foreach ($map as $needle => $label) {
		if ($host === $needle || str_ends_with($host, '.' . $needle)) {
			return $label;
		}
	}
	return preg_replace('/^www\./', '', $host);
}

function europulse_attachment_credit(int $attachment_id, ?string $lang = null): string {
	if ($attachment_id <= 0) {
		return '';
	}
	$lang = $lang ?: europulse_current_lang();
	$remote_url = (string) get_post_meta($attachment_id, '_epv2_remote_source_url', true);
	$source_label = trim((string) get_post_meta($attachment_id, '_epv2_remote_source_label', true));
	if ($source_label === '' && $remote_url !== '') {
		$source_label = europulse_source_label_from_url($remote_url);
	} elseif ($remote_url !== '' && preg_match('/\./', $source_label)) {
		$source_label = europulse_source_label_from_url($remote_url);
	}
	$alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
	$alt_words = $alt !== '' ? preg_split('/\s+/u', $alt, -1, PREG_SPLIT_NO_EMPTY) : [];
	$descriptive_alt = $alt !== ''
		&& ! preg_match('/[:.!?]/u', $alt)
		&& (function_exists('mb_strlen') ? mb_strlen($alt, 'UTF-8') : strlen($alt)) <= 48
		&& is_array($alt_words)
		&& count($alt_words) <= 4
		&& ($source_label === '' || mb_strtolower($alt) !== mb_strtolower($source_label));
	if ($source_label !== '') {
		if ($descriptive_alt) {
			return europulse_media_credit_prefix($lang) . ': ' . $alt . ' — ' . $source_label;
		}
		return europulse_media_credit_prefix($lang) . ': ' . $source_label;
	}
	$caption = trim((string) wp_get_attachment_caption($attachment_id));
	if ($caption === '') {
		return '';
	}
	$caption = preg_replace('/^(Фото|Photo|Bild|Источник изображения|Фото \/ видео)\s*:\s*/u', '', $caption) ?: $caption;
	return europulse_media_credit_prefix($lang) . ': ' . trim($caption);
}

function europulse_post_localized_image_alt(int $post_id): string {
	$post_id = (int) $post_id;
	if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
		return '';
	}
	if (! get_post_meta($post_id, '_epv2_queue_id', true)) {
		return '';
	}
	$title = trim((string) get_the_title($post_id));
	if ($title !== '') {
		return $title;
	}
	$excerpt = trim(wp_strip_all_tags((string) get_the_excerpt($post_id)));
	if ($excerpt !== '') {
		return $excerpt;
	}
	return '';
}

function europulse_contextual_image_post_id(int $attachment_id): int {
	$attachment_id = (int) $attachment_id;
	if ($attachment_id <= 0 || is_admin()) {
		return 0;
	}
	$post_id = (int) get_the_ID();
	if ($post_id > 0 && get_post_thumbnail_id($post_id) === $attachment_id) {
		return $post_id;
	}
	if (is_singular('post')) {
		$queried_id = (int) get_queried_object_id();
		if ($queried_id > 0 && get_post_thumbnail_id($queried_id) === $attachment_id) {
			return $queried_id;
		}
	}
	return 0;
}

add_filter('wp_get_attachment_image_attributes', function (array $attr, $attachment, $size) {
	$attachment_id = 0;
	if (is_object($attachment) && ! empty($attachment->ID)) {
		$attachment_id = (int) $attachment->ID;
	} elseif (is_numeric($attachment)) {
		$attachment_id = (int) $attachment;
	}
	$post_id = europulse_contextual_image_post_id($attachment_id);
	if ($post_id <= 0) {
		return $attr;
	}
	$alt = europulse_post_localized_image_alt($post_id);
	if ($alt !== '') {
		$attr['alt'] = $alt;
	}
	return $attr;
}, 20, 3);

add_filter('rank_math/opengraph/facebook/image_array', function ($attachment) {
	if (! is_array($attachment) || ! is_singular('post')) {
		return $attachment;
	}
	$alt = europulse_post_localized_image_alt((int) get_queried_object_id());
	if ($alt !== '') {
		$attachment['alt'] = $alt;
	}
	return $attachment;
});

add_filter('rank_math/opengraph/twitter/image_array', function ($attachment) {
	if (! is_array($attachment) || ! is_singular('post')) {
		return $attachment;
	}
	$alt = europulse_post_localized_image_alt((int) get_queried_object_id());
	if ($alt !== '') {
		$attachment['alt'] = $alt;
	}
	return $attachment;
});

add_filter('rank_math/json_ld', function ($data, $jsonld) {
	if (! is_array($data) || ! is_singular('post')) {
		return $data;
	}
	$alt = europulse_post_localized_image_alt((int) get_queried_object_id());
	if ($alt === '') {
		return $data;
	}
	array_walk_recursive($data, function (&$value, $key) use ($alt) {
		if ($key === 'caption' && is_string($value) && $value !== '') {
			$value = $alt;
		}
	});
	return $data;
}, 20, 2);

function europulse_localize_topic_label(string $label, ?string $lang = null): string {
	$lang = $lang ?: europulse_current_lang();
	$normalized = trim($label);
	if ($normalized === '') {
		return '';
	}
	$map = [
		'Война в Украине' => ['de' => 'Krieg in der Ukraine', 'uk' => 'Війна в Україні', 'en' => 'War in Ukraine'],
		'War in Ukraine' => ['de' => 'Krieg in der Ukraine', 'uk' => 'Війна в Україні', 'en' => 'War in Ukraine'],
		'Krieg in der Ukraine' => ['de' => 'Krieg in der Ukraine', 'uk' => 'Війна в Україні', 'en' => 'War in Ukraine'],
		'Війна в Україні' => ['de' => 'Krieg in der Ukraine', 'uk' => 'Війна в Україні', 'en' => 'War in Ukraine'],
		'Иран и Ближний Восток' => ['de' => 'Iran und Nahost', 'uk' => 'Іран і Близький Схід', 'en' => 'Iran and the Middle East'],
		'Iran and the Middle East' => ['de' => 'Iran und Nahost', 'uk' => 'Іран і Близький Схід', 'en' => 'Iran and the Middle East'],
		'Iran und Nahost' => ['de' => 'Iran und Nahost', 'uk' => 'Іран і Близький Схід', 'en' => 'Iran and the Middle East'],
		'Іран і Близький Схід' => ['de' => 'Iran und Nahost', 'uk' => 'Іран і Близький Схід', 'en' => 'Iran and the Middle East'],
		'Цены на топливо и энергия' => ['de' => 'Spritpreise und Energie', 'uk' => 'Ціни на пальне та енергію', 'en' => 'Fuel Prices and Energy'],
		'Fuel Prices and Energy' => ['de' => 'Spritpreise und Energie', 'uk' => 'Ціни на пальне та енергію', 'en' => 'Fuel Prices and Energy'],
		'Spritpreise und Energie' => ['de' => 'Spritpreise und Energie', 'uk' => 'Ціни на пальне та енергію', 'en' => 'Fuel Prices and Energy'],
		'Ціни на пальне та енергію' => ['de' => 'Spritpreise und Energie', 'uk' => 'Ціни на пальне та енергію', 'en' => 'Fuel Prices and Energy'],
		'Транспорт и движение' => ['de' => 'Verkehr und Mobilitaet', 'uk' => 'Транспорт і рух', 'en' => 'Transport and Mobility'],
		'Transport and Mobility' => ['de' => 'Verkehr und Mobilitaet', 'uk' => 'Транспорт і рух', 'en' => 'Transport and Mobility'],
		'Verkehr und Mobilitaet' => ['de' => 'Verkehr und Mobilitaet', 'uk' => 'Транспорт і рух', 'en' => 'Transport and Mobility'],
		'Транспорт і рух' => ['de' => 'Verkehr und Mobilitaet', 'uk' => 'Транспорт і рух', 'en' => 'Transport and Mobility'],
		'Миграция и пребывание' => ['de' => 'Migration und Aufenthalt', 'uk' => 'Міграція та перебування', 'en' => 'Migration and Residency'],
		'Migration and Residency' => ['de' => 'Migration und Aufenthalt', 'uk' => 'Міграція та перебування', 'en' => 'Migration and Residency'],
		'Migration und Aufenthalt' => ['de' => 'Migration und Aufenthalt', 'uk' => 'Міграція та перебування', 'en' => 'Migration and Residency'],
		'Міграція та перебування' => ['de' => 'Migration und Aufenthalt', 'uk' => 'Міграція та перебування', 'en' => 'Migration and Residency'],
		'Выплаты и рынок труда' => ['de' => 'Leistungen und Arbeitsmarkt', 'uk' => 'Виплати та ринок праці', 'en' => 'Benefits and the Labour Market'],
		'Benefits and the Labour Market' => ['de' => 'Leistungen und Arbeitsmarkt', 'uk' => 'Виплати та ринок праці', 'en' => 'Benefits and the Labour Market'],
		'Leistungen und Arbeitsmarkt' => ['de' => 'Leistungen und Arbeitsmarkt', 'uk' => 'Виплати та ринок праці', 'en' => 'Benefits and the Labour Market'],
		'Виплати та ринок праці' => ['de' => 'Leistungen und Arbeitsmarkt', 'uk' => 'Виплати та ринок праці', 'en' => 'Benefits and the Labour Market'],
		'Жильё и аренда' => ['de' => 'Wohnen und Miete', 'uk' => 'Житло та оренда', 'en' => 'Housing and Rent'],
		'Housing and Rent' => ['de' => 'Wohnen und Miete', 'uk' => 'Житло та оренда', 'en' => 'Housing and Rent'],
		'Образование и дети' => ['de' => 'Bildung und Kinder', 'uk' => 'Освіта та діти', 'en' => 'Education and Children'],
		'Education and Children' => ['de' => 'Bildung und Kinder', 'uk' => 'Освіта та діти', 'en' => 'Education and Children'],
		'Европейская политика' => ['de' => 'Europaeische Politik', 'uk' => 'Європейська політика', 'en' => 'European Politics'],
		'European Politics' => ['de' => 'Europaeische Politik', 'uk' => 'Європейська політика', 'en' => 'European Politics'],
		'Europaeische Politik' => ['de' => 'Europaeische Politik', 'uk' => 'Європейська політика', 'en' => 'European Politics'],
		'Європейська політика' => ['de' => 'Europaeische Politik', 'uk' => 'Європейська політика', 'en' => 'European Politics'],
		'Германия' => ['de' => 'Deutschland', 'uk' => 'Німеччина', 'en' => 'Germany'],
		'Germany' => ['de' => 'Deutschland', 'uk' => 'Німеччина', 'en' => 'Germany'],
		'Deutschland' => ['de' => 'Deutschland', 'uk' => 'Німеччина', 'en' => 'Germany'],
		'Німеччина' => ['de' => 'Deutschland', 'uk' => 'Німеччина', 'en' => 'Germany'],
		'Бавария' => ['de' => 'Bayern', 'uk' => 'Баварія', 'en' => 'Bavaria'],
		'Bavaria' => ['de' => 'Bayern', 'uk' => 'Баварія', 'en' => 'Bavaria'],
		'Bayern' => ['de' => 'Bayern', 'uk' => 'Баварія', 'en' => 'Bavaria'],
		'Баварія' => ['de' => 'Bayern', 'uk' => 'Баварія', 'en' => 'Bavaria'],
		'Мюнхен' => ['de' => 'Muenchen', 'uk' => 'Мюнхен', 'en' => 'Munich'],
		'Munich' => ['de' => 'Muenchen', 'uk' => 'Мюнхен', 'en' => 'Munich'],
		'Muenchen' => ['de' => 'Muenchen', 'uk' => 'Мюнхен', 'en' => 'Munich'],
		'Украина' => ['de' => 'Ukraine', 'uk' => 'Україна', 'en' => 'Ukraine'],
		'Ukraine' => ['de' => 'Ukraine', 'uk' => 'Україна', 'en' => 'Ukraine'],
		'Україна' => ['de' => 'Ukraine', 'uk' => 'Україна', 'en' => 'Ukraine'],
		'Европа' => ['de' => 'Europa', 'uk' => 'Європа', 'en' => 'Europe'],
		'Europe' => ['de' => 'Europa', 'uk' => 'Європа', 'en' => 'Europe'],
		'Europa' => ['de' => 'Europa', 'uk' => 'Європа', 'en' => 'Europe'],
		'Європа' => ['de' => 'Europa', 'uk' => 'Європа', 'en' => 'Europe'],
		'Мир' => ['de' => 'Welt', 'uk' => 'Світ', 'en' => 'World'],
		'World' => ['de' => 'Welt', 'uk' => 'Світ', 'en' => 'World'],
		'Welt' => ['de' => 'Welt', 'uk' => 'Світ', 'en' => 'World'],
		'Світ' => ['de' => 'Welt', 'uk' => 'Світ', 'en' => 'World'],
		'Политика' => ['de' => 'Politik', 'uk' => 'Політика', 'en' => 'Politics'],
		'Politics' => ['de' => 'Politik', 'uk' => 'Політика', 'en' => 'Politics'],
		'Politik' => ['de' => 'Politik', 'uk' => 'Політика', 'en' => 'Politics'],
		'Політика' => ['de' => 'Politik', 'uk' => 'Політика', 'en' => 'Politics'],
		'Экономика' => ['de' => 'Wirtschaft', 'uk' => 'Економіка', 'en' => 'Economy'],
		'Economy' => ['de' => 'Wirtschaft', 'uk' => 'Економіка', 'en' => 'Economy'],
		'Wirtschaft' => ['de' => 'Wirtschaft', 'uk' => 'Економіка', 'en' => 'Economy'],
		'Економіка' => ['de' => 'Wirtschaft', 'uk' => 'Економіка', 'en' => 'Economy'],
		'Жизнь в Германии' => ['de' => 'Leben in Deutschland', 'uk' => 'Життя в Німеччині', 'en' => 'Life in Germany'],
		'Life in Germany' => ['de' => 'Leben in Deutschland', 'uk' => 'Життя в Німеччині', 'en' => 'Life in Germany'],
		'Leben in Deutschland' => ['de' => 'Leben in Deutschland', 'uk' => 'Життя в Німеччині', 'en' => 'Life in Germany'],
		'Життя в Німеччині' => ['de' => 'Leben in Deutschland', 'uk' => 'Життя в Німеччині', 'en' => 'Life in Germany'],
		'Сообщество' => ['de' => 'Community', 'uk' => 'Спільнота', 'en' => 'Community'],
		'Community' => ['de' => 'Community', 'uk' => 'Спільнота', 'en' => 'Community'],
		'Спільнота' => ['de' => 'Community', 'uk' => 'Спільнота', 'en' => 'Community'],
		'Культура' => ['de' => 'Kultur', 'uk' => 'Культура', 'en' => 'Culture'],
		'Culture' => ['de' => 'Kultur', 'uk' => 'Культура', 'en' => 'Culture'],
		'Kultur' => ['de' => 'Kultur', 'uk' => 'Культура', 'en' => 'Culture'],
		'Спорт' => ['de' => 'Sport', 'uk' => 'Спорт', 'en' => 'Sport'],
		'Sport' => ['de' => 'Sport', 'uk' => 'Спорт', 'en' => 'Sport'],
		'Ключевая тема недели' => ['de' => 'Wochenthema', 'uk' => 'Тема тижня', 'en' => 'Topic of the Week'],
		'Topic of the Week' => ['de' => 'Wochenthema', 'uk' => 'Тема тижня', 'en' => 'Topic of the Week'],
	];
	return $map[$normalized][$lang] ?? $normalized;
}

function europulse_story_topic_for_post(int $post_id): string {
	$topic = trim((string) get_post_meta($post_id, 'europulse_story_topic', true));
	$topic = europulse_localize_topic_label($topic);
	if ($topic === '') {
		return '';
	}
	$primary = trim((string) get_post_meta($post_id, '_epv2_primary_category', true));

	$title = mb_strtolower(trim(wp_strip_all_tags(get_the_title($post_id))));
	$excerpt = mb_strtolower(trim(wp_strip_all_tags((string) get_the_excerpt($post_id))));
	$topic_tokens = preg_split('/\s+/u', mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $topic) ?: $topic))) ?: [];
	$topic_tokens = array_values(array_filter(array_map('trim', $topic_tokens), static function (string $token): bool {
		return mb_strlen($token) >= 4;
	}));
	if ($topic_tokens === []) {
		return '';
	}

	$haystack = $title . ' ' . $excerpt;
	$overlap = 0;
	foreach (array_unique($topic_tokens) as $token) {
		if (mb_strpos($haystack, $token) !== false) {
			$overlap++;
		}
	}

	if ($overlap >= 2) {
		return europulse_topic_fits_primary_category($topic, $primary) ? $topic : '';
	}

	$permitted = [
		'war in ukraine',
		'krieg in der ukraine',
		'війна в україні',
		'iran and the middle east',
		'iran und nahost',
		'іран і близький схід',
	];

	if (! in_array(mb_strtolower($topic), $permitted, true)) {
		return '';
	}

	return europulse_topic_fits_primary_category($topic, $primary) ? $topic : '';
}

function europulse_topic_fits_primary_category(string $topic, string $primary): bool {
	$topic = mb_strtolower(trim($topic));
	$primary = sanitize_key($primary);
	if ($topic === '' || $primary === '') {
		return true;
	}
	$map = [
		'war in ukraine' => ['ukraine', 'world', 'politik'],
		'krieg in der ukraine' => ['ukraine', 'world', 'politik'],
		'війна в україні' => ['ukraine', 'world', 'politik'],
		'iran and the middle east' => ['world', 'politik'],
		'iran und nahost' => ['world', 'politik'],
		'іран і близький схід' => ['world', 'politik'],
		'fuel prices and energy' => ['wirtschaft', 'deutschland', 'leben-in-deutschland'],
		'spritpreise und energie' => ['wirtschaft', 'deutschland', 'leben-in-deutschland'],
		'ціни на пальне та енергію' => ['wirtschaft', 'deutschland', 'leben-in-deutschland'],
		'transport and mobility' => ['deutschland', 'bayern', 'münchen', 'europa'],
		'verkehr und mobilitaet' => ['deutschland', 'bayern', 'münchen', 'europa'],
		'транспорт і рух' => ['deutschland', 'bayern', 'münchen', 'europa'],
		'migration and residency' => ['leben-in-deutschland', 'deutschland', 'community'],
		'migration und aufenthalt' => ['leben-in-deutschland', 'deutschland', 'community'],
		'міграція та перебування' => ['leben-in-deutschland', 'deutschland', 'community'],
		'benefits and the labour market' => ['leben-in-deutschland', 'wirtschaft', 'deutschland'],
		'leistungen und arbeitsmarkt' => ['leben-in-deutschland', 'wirtschaft', 'deutschland'],
		'виплати та ринок праці' => ['leben-in-deutschland', 'wirtschaft', 'deutschland'],
		'european politics' => ['europa', 'politik', 'world'],
		'europaeische politik' => ['europa', 'politik', 'world'],
		'європейська політика' => ['europa', 'politik', 'world'],
	];
	$allowed = $map[$topic] ?? [];
	return $allowed === [] || in_array($primary, $allowed, true);
}

function europulse_current_locale(): string {
	return match (europulse_current_lang()) {
		'uk' => 'uk_UA',
		'en' => 'en_US',
		default => 'de_DE',
	};
}

function europulse_format_timestamp(int $timestamp, string $pattern = 'd. MMMM yyyy'): string {
	$locale = europulse_current_locale();

	if (class_exists('IntlDateFormatter')) {
		$formatter = new IntlDateFormatter(
			$locale,
			IntlDateFormatter::NONE,
			IntlDateFormatter::NONE,
			wp_timezone_string() ?: 'UTC',
			IntlDateFormatter::GREGORIAN,
			$pattern
		);

		if ($formatter) {
			$value = $formatter->format($timestamp);

			if (is_string($value) && $value !== '') {
				return $value;
			}
		}
	}

	return wp_date('d. F Y', $timestamp);
}

function europulse_format_post_date(int $post_id, string $pattern = 'd. MMMM yyyy'): string {
	$timestamp = get_post_time('U', true, $post_id);

	return europulse_format_timestamp((int) $timestamp, $pattern);
}

function europulse_format_city_time(string $timezone): string {
	try {
		$date = new DateTimeImmutable('now', new DateTimeZone($timezone));
	} catch (Exception $e) {
		return gmdate('H:i');
	}

	return $date->format('H:i');
}

function europulse_render_utility_times(): string {
	return '<span class="europulse-utility-time-city">' . esc_html(sprintf('%s %s', europulse_t('city_berlin'), europulse_format_city_time('Europe/Berlin'))) . '</span>';
}

function europulse_is_breaking($post_id = null) {
	$post_id = $post_id ?: get_the_ID();
	$enabled = (bool) get_post_meta($post_id, 'europulse_breaking', true);

	if (! $enabled) {
		return false;
	}

	$until = (int) get_post_meta($post_id, 'europulse_breaking_until', true);

	if (! $until) {
		return true;
	}

	return $until > time();
}

function europulse_is_sponsored($post_id = null) {
	$post_id = $post_id ?: get_the_ID();

	return (bool) get_post_meta($post_id, 'europulse_sponsored', true);
}

function europulse_is_top_story($post_id = null): bool {
	$post_id = $post_id ?: get_the_ID();

	return (bool) get_post_meta($post_id, 'europulse_top_story', true);
}

function europulse_has_video($post_id = null): bool {
	$post_id = $post_id ?: get_the_ID();
	$content = (string) get_post_field('post_content', $post_id);

	return str_contains($content, '<video') || str_contains($content, 'wp-block-video');
}

function europulse_video_poster_url(int $post_id): string {
	$poster = (string) get_post_meta($post_id, 'europulse_video_poster', true);

	if ($poster !== '') {
		return $poster;
	}

	return (string) get_the_post_thumbnail_url($post_id, 'large');
}

function europulse_social_links() {
	$links = [
		'facebook' => get_option('europulse_social_facebook', 'https://www.facebook.com/'),
		'telegram' => get_option('europulse_social_telegram', 'https://t.me/'),
		'youtube' => get_option('europulse_social_youtube', 'https://www.youtube.com/'),
	];

	$placeholder_hosts = [
		'facebook.com',
		'www.facebook.com',
		't.me',
		'www.youtube.com',
		'youtube.com',
	];

	$filtered = [];
	foreach ($links as $network => $url) {
		$url = trim((string) $url);
		if ($url === '') {
			continue;
		}

		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
		if (in_array($host, $placeholder_hosts, true) && $path === '') {
			continue;
		}

		$filtered[$network] = $url;
	}

	return $filtered;
}

function europulse_label_chip($post_id = null): string {
	if (europulse_is_sponsored($post_id)) {
		return '<span class="europulse-sponsored-chip">' . esc_html(europulse_t('ad')) . '</span>';
	}

	if (europulse_is_breaking($post_id)) {
		return '<span class="europulse-breaking-chip">' . esc_html(europulse_t('breaking')) . '</span>';
	}

	return '';
}

function europulse_social_icon($network) {
	$icons = [
		'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.72 21v-8.03h2.7l.4-3.13h-3.1V7.85c0-.9.25-1.51 1.55-1.51h1.66V3.53c-.29-.04-1.28-.13-2.43-.13-2.41 0-4.07 1.47-4.07 4.18v2.26H7.7v3.13h2.73V21h3.29Z" fill="currentColor"/></svg>',
		'telegram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.2 5.05 3.78 11.77c-1.2.48-1.19 1.16-.22 1.46l4.47 1.4 1.72 5.38c.2.57.1.8.7.8.46 0 .66-.21.92-.47l2.16-2.1 4.5 3.32c.83.46 1.43.23 1.64-.78l2.96-13.95c.29-1.2-.46-1.74-1.43-1.36ZM9.04 14.39l9.92-6.26c.49-.3.95-.14.58.2l-8.17 7.38-.32 3.44-2.01-4.76Z" fill="currentColor"/></svg>',
		'youtube' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.8 7.11a3 3 0 0 0-2.1-2.12C17.84 4.5 12 4.5 12 4.5s-5.84 0-7.7.49A3 3 0 0 0 2.2 7.1 31.2 31.2 0 0 0 1.7 12c0 1.72.17 3.35.5 4.89A3 3 0 0 0 4.3 19c1.86.5 7.7.5 7.7.5s5.84 0 7.7-.5a3 3 0 0 0 2.1-2.11c.33-1.54.5-3.17.5-4.89 0-1.72-.17-3.35-.5-4.89ZM10.02 15.72V8.28L16.2 12l-6.18 3.72Z" fill="currentColor"/></svg>',
	];

	return $icons[$network] ?? '';
}

function europulse_localized_term_id(int $term_id): int {
	if (! function_exists('pll_get_term')) {
		return $term_id;
	}

	$lang = europulse_current_lang();

	return (int) (pll_get_term($term_id, $lang) ?: $term_id);
}

function europulse_category_post_ids_for_current_lang(int $term_id, int $limit, int $offset = 0): array {
	$ids = [];
	$lang = europulse_current_lang();
	$canonical_slug = class_exists('EPV2_Taxonomy_Map') ? EPV2_Taxonomy_Map::canonical_slug_for_term_id($term_id) : '';
	$pool = europulse_autopilot_home_pool();

	if ($canonical_slug !== '') {
		$primary_query = new WP_Query([
			'post_type' => 'post',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'ignore_sticky_posts' => true,
			'meta_query' => [
				[
					'key' => '_epv2_queue_id',
					'compare' => 'EXISTS',
				],
				[
					'key' => '_epv2_primary_category',
					'value' => $canonical_slug,
				],
			],
			'orderby' => [
				'date' => 'DESC',
			],
			'fields' => 'ids',
			'no_found_rows' => true,
		]);

		foreach ((array) $primary_query->posts as $post_id) {
			if (! europulse_home_post_is_eligible((int) $post_id)) {
				continue;
			}
			$post_lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language((int) $post_id) : '';

			if ($post_lang !== '' && $post_lang !== $lang) {
				continue;
			}

			$entry = $pool[(int) $post_id] ?? null;
			if (! europulse_home_feature_is_zone_eligible($entry, 'section')) {
				continue;
			}

			$ids[] = (int) $post_id;
		}

		wp_reset_postdata();
	}

	$query = new WP_Query([
		'post_type' => 'post',
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'ignore_sticky_posts' => true,
		'category__in' => [$term_id],
		'meta_query' => [
			[
				'key' => '_epv2_queue_id',
				'compare' => 'EXISTS',
			],
		],
		'orderby' => [
			'date' => 'DESC',
		],
		'fields' => 'ids',
		'no_found_rows' => true,
	]);

	foreach ((array) $query->posts as $post_id) {
		if (! europulse_home_post_is_eligible((int) $post_id)) {
			continue;
		}
		$post_lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language((int) $post_id) : '';

		if ($post_lang !== '' && $post_lang !== $lang) {
			continue;
		}

		$entry = $pool[(int) $post_id] ?? null;
		if (! europulse_home_feature_is_zone_eligible($entry, 'section')) {
			continue;
		}

		if ($canonical_slug !== '') {
			$queue_id = (int) get_post_meta((int) $post_id, '_epv2_queue_id', true);
			$primary_category = (string) get_post_meta((int) $post_id, '_epv2_primary_category', true);
			if ($queue_id > 0 && $primary_category !== '' && $primary_category !== $canonical_slug) {
				continue;
			}
		}

		if (! in_array((int) $post_id, $ids, true)) {
			$ids[] = (int) $post_id;
		}
	}

	wp_reset_postdata();

	if ($ids === []) {
		$fallback_terms = [$term_id];
		if ($canonical_slug === 'community') {
			$fallback_terms = array_values(array_unique(array_filter([
				$term_id,
				(int) (EPV2_Taxonomy_Map::map('community', 'de')['term_id'] ?? 0),
				(int) (EPV2_Taxonomy_Map::map('community', 'uk')['term_id'] ?? 0),
				(int) (EPV2_Taxonomy_Map::map('community', 'en')['term_id'] ?? 0),
				46,
			])));
		}

		$fallback_terms = array_values(array_unique(array_filter([
			...$fallback_terms,
		])));
		$fallback_query = new WP_Query([
			'post_type' => 'post',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'ignore_sticky_posts' => true,
			'category__in' => $fallback_terms,
			'lang' => '',
			'suppress_filters' => true,
			'orderby' => [
				'date' => 'DESC',
			],
			'fields' => 'ids',
			'no_found_rows' => true,
		]);

		foreach ((array) $fallback_query->posts as $post_id) {
			if (! europulse_home_post_is_eligible((int) $post_id)) {
				continue;
			}
			$post_lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language((int) $post_id) : '';
			if ($post_lang !== '' && $post_lang !== $lang) {
				continue;
			}
			$entry = $pool[(int) $post_id] ?? null;
			if (! europulse_home_feature_is_zone_eligible($entry, 'section')) {
				continue;
			}
			$ids[] = (int) $post_id;
		}

		wp_reset_postdata();
	}

	if ($offset > 0) {
		$ids = array_slice($ids, $offset);
	}

	return array_slice($ids, 0, max(1, $limit));
}

function europulse_section_age_limit_hours(string $canonical_slug): int {
	return match ($canonical_slug) {
		'community' => 36,
		'leben-in-deutschland' => 24 * 30,
		'kultur' => 24 * 30,
		'sport' => 24 * 14,
		'world' => 24 * 14,
		default => 24 * 14,
	};
}

function europulse_home_section_ids(string $canonical_slug, int $limit, int $offset = 0, array $exclude_ids = []): array {
	$canonical_slug = sanitize_title($canonical_slug);
	$limit = max(1, $limit);
	$offset = max(0, $offset);
	$exclude_ids = array_values(array_unique(array_map('intval', $exclude_ids)));
	$pool = europulse_autopilot_home_pool();
	$now = time();
	$age_limit_hours = europulse_section_age_limit_hours($canonical_slug);
	$candidates = [];

	foreach ($pool as $post_id => $entry) {
		if ((string) ($entry['primary_category'] ?? '') !== $canonical_slug) {
			continue;
		}
		if (! europulse_home_feature_is_zone_eligible($entry, 'section')) {
			continue;
		}
		if (in_array((int) $post_id, $exclude_ids, true)) {
			continue;
		}

		$age_hours = max(0, ($now - (int) ($entry['timestamp'] ?? $now)) / HOUR_IN_SECONDS);
		if ($age_hours > $age_limit_hours) {
			continue;
		}

		$score = (int) ($entry['timestamp'] ?? 0);
		$score += min(7200, ((int) ($entry['story_score'] ?? 0)) * 120);
		$score += min(5400, ((int) ($entry['popular_score'] ?? 0)) * 90);
		if (! empty($entry['breaking'])) {
			$score += 3600;
		}
		if (! empty($entry['top_story'])) {
			$score += 2400;
		}
		if (! empty($entry['has_video'])) {
			$score += 900;
		}

		$candidates[] = [
			'post_id' => (int) $post_id,
			'score' => $score,
			'timestamp' => (int) ($entry['timestamp'] ?? 0),
		];
	}

	usort($candidates, static function (array $left, array $right): int {
		if ($left['score'] === $right['score']) {
			return $right['timestamp'] <=> $left['timestamp'];
		}

		return $right['score'] <=> $left['score'];
	});

	$post_ids = array_map(static fn(array $item): int => (int) $item['post_id'], $candidates);

	if ($offset > 0) {
		$post_ids = array_slice($post_ids, $offset);
	}

	$post_ids = array_slice($post_ids, 0, $limit);
	if ($post_ids !== []) {
		return $post_ids;
	}

	$fallback_query = new WP_Query([
		'post_type' => 'post',
		'post_status' => 'publish',
		'posts_per_page' => $limit + $offset + 6,
		'orderby' => 'date',
		'order' => 'DESC',
		'post__not_in' => $exclude_ids,
		'meta_query' => [
			'relation' => 'AND',
			[
				'key' => '_epv2_queue_id',
				'compare' => 'EXISTS',
			],
			[
				'key' => '_epv2_primary_category',
				'value' => $canonical_slug,
			],
		],
		'date_query' => [
			[
				'after' => gmdate('Y-m-d H:i:s', time() - ($age_limit_hours * HOUR_IN_SECONDS)),
				'inclusive' => true,
			],
		],
		'lang' => europulse_current_lang(),
		'ignore_sticky_posts' => true,
		'fields' => 'ids',
	]);

	$fallback_ids = array_values(array_filter(array_map('intval', $fallback_query->posts), static function (int $post_id): bool {
		if ($post_id <= 0 || ! europulse_home_post_is_eligible($post_id)) {
			return false;
		}
		$entry = europulse_autopilot_home_pool()[$post_id] ?? null;
		return europulse_home_feature_is_zone_eligible($entry, 'section');
	}));

	if ($offset > 0) {
		$fallback_ids = array_slice($fallback_ids, $offset);
	}

	return array_slice($fallback_ids, 0, $limit);
}

function europulse_home_post_is_eligible(int $post_id, ?WP_Post $post = null): bool {
	static $selection_cache = [];

	$post = $post ?: get_post($post_id);
	if (! $post || $post->post_status !== 'publish') {
		return false;
	}
	if ((int) get_post_meta($post_id, 'europulse_demo_post', true) === 1) {
		return false;
	}

	$title = trim(wp_strip_all_tags((string) $post->post_title));
	$patterns = [
		'/\btagesschau in 100 sekunden\b/ui',
		'/\bin 100 sekunden\b/ui',
		'/\b100[\s-]?sekunden\b/ui',
		'/\b100[\s-]?second(?:s)?\b/ui',
		'/\b100[\s-]?секунд\w*\b/ui',
		'/^news$/ui',
		'/\bps plus\b|\bplaystation plus\b|\bxbox game pass\b/ui',
	];

	foreach ($patterns as $pattern) {
		if (preg_match($pattern, $title) === 1) {
			return false;
		}
	}

	if (! array_key_exists($post_id, $selection_cache)) {
		$selection_cache[$post_id] = true;
		$queue_id = (int) get_post_meta($post_id, '_epv2_queue_id', true);

		$post_decision = sanitize_key((string) get_post_meta($post_id, 'europulse_selection_decision', true));
		$post_score = (int) get_post_meta($post_id, 'europulse_selection_score', true);
		$queue_decision = '';
		$queue_score = 0;

		if ($post_decision === '' && $queue_id > 0) {
			global $wpdb;
			$admin_notes = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT admin_notes FROM {$wpdb->prefix}epv2_queue WHERE id = %d",
					$queue_id
				)
			);
			$data = json_decode((string) $admin_notes, true);
			$queue_decision = sanitize_key((string) ($data['selection']['decision'] ?? ''));
			$queue_score = (int) ($data['selection']['score'] ?? 0);
		}
		$selection = europulse_home_resolve_selection($post_decision, $post_score, $queue_decision, $queue_score);
		$decision = (string) $selection['decision'];
		$score = (int) $selection['score'];

		$is_breaking = (int) get_post_meta($post_id, 'europulse_breaking', true) === 1;
		$is_top_story = (int) get_post_meta($post_id, 'europulse_top_story', true) === 1;

		if (! $is_breaking && ! $is_top_story) {
			if ($decision === 'reject' || $decision === 'low') {
				$selection_cache[$post_id] = false;
			} elseif ($decision === 'review' && $score < 40) {
				$selection_cache[$post_id] = false;
			}
		}
	}

	if (! $selection_cache[$post_id]) {
		return false;
	}

	return true;
}

function europulse_home_resolve_selection(string $post_decision, int $post_score, string $queue_decision = '', int $queue_score = 0): array {
	$post_decision = sanitize_key($post_decision);
	$queue_decision = sanitize_key($queue_decision);

	if ($post_decision !== '') {
		return [
			'decision' => $post_decision,
			'score' => max(0, $post_score),
			'source' => 'post',
		];
	}

	return [
		'decision' => $queue_decision,
		'score' => max(0, $queue_score),
		'source' => $queue_decision !== '' ? 'queue' : '',
	];
}

add_action('pre_get_posts', function (WP_Query $query): void {
	if (is_admin() || ! $query->is_main_query()) {
		return;
	}

	if (is_singular() || (! $query->is_home() && ! $query->is_category() && ! $query->is_tag() && ! $query->is_archive() && ! $query->is_search())) {
		return;
	}

	if ($query->is_home() && ! $query->is_front_page()) {
		$query->set('posts_per_page', 1);
		$query->set('ignore_sticky_posts', true);
		$query->set('no_found_rows', true);
		return;
	}

	$post_type = $query->get('post_type');
	if ($post_type && $post_type !== 'post' && $post_type !== ['post']) {
		return;
	}

	// Published archives/search should list already-published posts directly.
	// The publish gate and homepage pool handle editorial eligibility upstream;
	// adding this OR-heavy postmeta filter to every cold category request costs
	// about 1s on populated archives.
	return;
}, 20);

function europulse_autopilot_home_pool(): array {
	static $cache = [];
	$lang = europulse_current_lang();

	if (isset($cache[$lang])) {
		return $cache[$lang];
	}

	$last_changed = function_exists('wp_cache_get_last_changed') ? (string) wp_cache_get_last_changed('posts') : '';
	$persistent_cache_key = 'home_pool_' . md5($lang . '|' . $last_changed);
	$persistent_pool = wp_cache_get($persistent_cache_key, 'europulse_foundation');
	if (is_array($persistent_pool)) {
		return $cache[$lang] = $persistent_pool;
	}

	$query = new WP_Query([
		'post_type' => 'post',
		'post_status' => 'publish',
		'posts_per_page' => 240,
		'ignore_sticky_posts' => true,
		'lang' => '',
		'suppress_filters' => true,
		'meta_query' => [
			[
				'key' => '_epv2_queue_id',
				'compare' => 'EXISTS',
			],
		],
		'orderby' => [
			'date' => 'DESC',
		],
		'fields' => 'ids',
		'no_found_rows' => true,
	]);

	$all_ids = array_map('intval', (array) $query->posts);
	wp_reset_postdata();

	if ($all_ids === []) {
		return $cache[$lang] = [];
	}

	global $wpdb;
	$post_ids_sql = implode(',', array_map('intval', $all_ids));
	$meta_rows = $wpdb->get_results(
		"SELECT post_id, meta_key, meta_value
		FROM {$wpdb->postmeta}
		WHERE post_id IN ({$post_ids_sql})
		  AND meta_key IN ('_epv2_queue_id','_epv2_primary_category','_epv3_primary_category','_epv3_context_score','_epv3_seo_score','_epv3_google_score','_epv3_release_score','europulse_breaking','europulse_breaking_until','europulse_top_story','europulse_story_format','europulse_story_topic','europulse_popular_score','europulse_selection_decision','europulse_selection_score')",
		ARRAY_A
	);

	$meta_map = [];
	foreach ($meta_rows as $row) {
		$post_id = (int) ($row['post_id'] ?? 0);
		if ($post_id <= 0) {
			continue;
		}
		$meta_map[$post_id] = $meta_map[$post_id] ?? [];
		$meta_map[$post_id][(string) $row['meta_key']] = $row['meta_value'];
	}

	$queue_ids = [];
	foreach ($all_ids as $post_id) {
		$queue_id = (int) ($meta_map[$post_id]['_epv2_queue_id'] ?? 0);
		if ($queue_id > 0) {
			$queue_ids[] = $queue_id;
		}
	}
	$queue_ids = array_values(array_unique($queue_ids));
	$queue_map = [];
	if ($queue_ids !== []) {
		$queue_sql = implode(',', array_map('intval', $queue_ids));
		$queue_rows = $wpdb->get_results(
			"SELECT id, story_score, topic_label, admin_notes
			FROM {$wpdb->prefix}epv2_queue
			WHERE id IN ({$queue_sql})",
			ARRAY_A
		);
		foreach ($queue_rows as $row) {
			$admin_notes = json_decode((string) ($row['admin_notes'] ?? ''), true);
			$queue_map[(int) $row['id']] = [
				'story_score' => (int) ($row['story_score'] ?? 0),
				'topic_label' => (string) ($row['topic_label'] ?? ''),
				'selection_decision' => sanitize_key((string) ($admin_notes['selection']['decision'] ?? '')),
				'selection_score' => (int) ($admin_notes['selection']['score'] ?? 0),
			];
		}
	}

	$buckets = [];
	foreach ($all_ids as $post_id) {
		$post = get_post($post_id);
		if (! $post || $post->post_status !== 'publish') {
			continue;
		}
		if (! europulse_home_post_is_eligible((int) $post_id, $post)) {
			continue;
		}
		$meta = $meta_map[$post_id] ?? [];
		$queue_id = (int) ($meta['_epv2_queue_id'] ?? 0);
		if ($queue_id <= 0 && empty($meta['_epv3_release_score'])) {
			continue;
		}
		$post_lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language((int) $post_id) : '';
		$post_lang = $post_lang !== '' ? $post_lang : 'de';
		$buckets[$queue_id] = $buckets[$queue_id] ?? [];
		$buckets[$queue_id][$post_lang] = (int) $post_id;
	}

	$pool = [];
	foreach ($buckets as $queue_id => $translations) {
		$localized_post_id = (int) ($translations[$lang] ?? 0);
		if ($localized_post_id <= 0) {
			continue;
		}

		$representative_id = 0;
		foreach (['de', 'uk', 'en'] as $preferred_lang) {
			if (! empty($translations[$preferred_lang])) {
				$representative_id = (int) $translations[$preferred_lang];
				break;
			}
		}
		if ($representative_id <= 0) {
			$representative_id = (int) reset($translations);
		}

		$post = get_post($representative_id);
		if (! $post || $post->post_status !== 'publish') {
			continue;
		}

		$meta = $meta_map[$representative_id] ?? [];
		$content = (string) $post->post_content;
		$content_length = function_exists('mb_strlen') ? mb_strlen(wp_strip_all_tags($content), 'UTF-8') : strlen(wp_strip_all_tags($content));
		$topic_label = trim((string) ($meta['europulse_story_topic'] ?? ''));
		if ($topic_label === '' && $queue_id > 0) {
			$topic_label = trim((string) ($queue_map[$queue_id]['topic_label'] ?? ''));
		}
		$story_score = (int) ($queue_map[$queue_id]['story_score'] ?? 0);
		if ($story_score <= 0) {
			$story_score = europulse_epv3_story_score_from_meta($meta);
		}
		$selection = europulse_home_resolve_selection(
			(string) ($meta['europulse_selection_decision'] ?? ''),
			(int) ($meta['europulse_selection_score'] ?? 0),
			(string) ($queue_map[$queue_id]['selection_decision'] ?? ''),
			(int) ($queue_map[$queue_id]['selection_score'] ?? 0)
		);

		$pool[$localized_post_id] = [
			'post_id' => $localized_post_id,
			'representative_post_id' => $representative_id,
			'post_date_gmt' => (string) $post->post_date_gmt,
			'timestamp' => (int) get_post_time('U', true, $representative_id),
			'queue_id' => $queue_id,
			'primary_category' => (string) ($meta['_epv2_primary_category'] ?? $meta['_epv3_primary_category'] ?? ''),
			'breaking' => ! empty($meta['europulse_breaking']),
			'breaking_until' => (int) ($meta['europulse_breaking_until'] ?? 0),
			'top_story' => ! empty($meta['europulse_top_story']),
			'story_format' => (string) ($meta['europulse_story_format'] ?? ''),
			'topic_label' => $topic_label,
			'popular_score' => (int) ($meta['europulse_popular_score'] ?? 0),
			'story_score' => $story_score,
			'selection_decision' => (string) $selection['decision'],
			'selection_score' => (int) $selection['score'],
			'content_length' => (int) $content_length,
			'has_video' => europulse_has_video($representative_id),
		];
	}

	wp_cache_set($persistent_cache_key, $pool, 'europulse_foundation', 120);
	return $cache[$lang] = $pool;
}

function europulse_epv3_story_score_from_meta(array $meta): int {
	$context = (int) ($meta['_epv3_context_score'] ?? 0);
	$seo = (int) ($meta['_epv3_seo_score'] ?? 0);
	$google = (int) ($meta['_epv3_google_score'] ?? 0);
	$release = (int) ($meta['_epv3_release_score'] ?? 0);
	$story_score = (int) floor(($context * 0.30) + ($seo * 0.20) + ($google * 0.15) + ($release * 0.35));
	return max(0, min(100, $story_score));
}

function europulse_inferred_story_format(int $post_id, ?array $entry = null): string {
	$entry = $entry ?? (europulse_autopilot_home_pool()[$post_id] ?? []);
	$explicit = trim((string) ($entry['story_format'] ?? ''));
	if ($explicit !== '') {
		return $explicit;
	}

	$topic = trim((string) ($entry['topic_label'] ?? ''));
	$length = (int) ($entry['content_length'] ?? 0);
	$primary = (string) ($entry['primary_category'] ?? '');

	if ($topic !== '' && $length >= 2200) {
		return 'analysis';
	}

	if ($topic !== '' && $length >= 1700 && in_array($primary, ['politik', 'europa', 'world', 'wirtschaft', 'ukraine', 'deutschland'], true)) {
		return 'developing';
	}

	return '';
}

function europulse_home_feature_is_important(?array $entry): bool {
	if (! is_array($entry) || $entry === []) {
		return false;
	}

	if (! empty($entry['breaking']) || ! empty($entry['top_story'])) {
		return true;
	}

	$decision = sanitize_key((string) ($entry['selection_decision'] ?? ''));
	$score = (int) ($entry['selection_score'] ?? 0);
	$primary = sanitize_key((string) ($entry['primary_category'] ?? ''));

	if (in_array($decision, ['priority', 'strong'], true)) {
		return true;
	}

	if ($decision === 'review' && $score >= 40 && in_array($primary, ['politik', 'ukraine', 'europa', 'world', 'wirtschaft', 'deutschland'], true)) {
		return true;
	}

	return false;
}

function europulse_home_feature_is_zone_eligible(?array $entry, string $zone = ''): bool {
	if (! is_array($entry) || $entry === []) {
		return false;
	}

	if (! empty($entry['breaking']) || ! empty($entry['top_story'])) {
		return true;
	}

	$decision = sanitize_key((string) ($entry['selection_decision'] ?? ''));

	if (in_array($decision, ['low', 'reject'], true)) {
		return false;
	}

	if ($zone === 'slider' || $zone === 'most_read' || $zone === 'analysis') {
		return europulse_home_feature_is_important($entry);
	}

	return true;
}

function europulse_home_zone_ids(string $zone, int $limit = 3, array $exclude_ids = []): array {
	$zone = sanitize_key($zone);
	$limit = max(1, $limit);
	$exclude_ids = array_values(array_unique(array_map('intval', $exclude_ids)));
	$now = time();
	$pool = europulse_autopilot_home_pool();

	$candidates = [];
	foreach ($pool as $post_id => $entry) {
		if (in_array((int) $post_id, $exclude_ids, true)) {
			continue;
		}

		$age_hours = max(0, ($now - (int) ($entry['timestamp'] ?? $now)) / HOUR_IN_SECONDS);
		$primary = (string) ($entry['primary_category'] ?? '');
		$format = europulse_inferred_story_format((int) $post_id, $entry);
		$popular = (int) ($entry['popular_score'] ?? 0);
		$story = (int) ($entry['story_score'] ?? 0);
		$score = null;

		if (! europulse_home_feature_is_zone_eligible($entry, $zone)) {
			continue;
		}

		if ($zone === 'slider') {
			if ($age_hours > 168) {
				continue;
			}
			$score = 0;
			if (! empty($entry['breaking']) && (((int) ($entry['breaking_until'] ?? 0)) === 0 || (int) ($entry['breaking_until'] ?? 0) > $now)) {
				$score += 1200;
			}
			if (! empty($entry['top_story'])) {
				$score += 900;
			}
			$score += min(240, $popular * 8);
			$score += min(220, $story * 4);
			$score += max(0, 120 - (int) round($age_hours * 2));
			if (in_array($primary, ['politik', 'ukraine', 'europa', 'world', 'wirtschaft', 'deutschland'], true)) {
				$score += 40;
			}
			if ($format === 'analysis' || $format === 'developing') {
				$score += 50;
			}
			if (! empty($entry['has_video'])) {
				$score += 20;
			}
		} elseif ($zone === 'latest') {
			if ($age_hours > 168) {
				continue;
			}
			$score = (int) ($entry['timestamp'] ?? 0);
			if (! empty($entry['breaking']) || ! empty($entry['top_story'])) {
				$score -= 3600;
			}
			if ($format === 'analysis') {
				$score -= 1800;
			}
		} elseif ($zone === 'most_read') {
			if ($age_hours > 336) {
				continue;
			}
			$score = ($popular * 20) + min(200, $story * 2);
			if (! empty($entry['breaking'])) {
				$score += 120;
			}
			if (! empty($entry['top_story'])) {
				$score += 80;
			}
			$score += max(0, 48 - (int) round($age_hours));
		} elseif ($zone === 'analysis') {
			if ($age_hours > 720) {
				continue;
			}
			if ($format === '') {
				continue;
			}
			$score = ($format === 'analysis' ? 500 : 350)
				+ min(180, $story * 3)
				+ min(120, (int) floor(($entry['content_length'] ?? 0) / 40))
				+ (! empty($entry['topic_label']) ? 60 : 0)
				+ max(0, 72 - (int) round($age_hours / 2));
		}

		if ($score === null) {
			continue;
		}

		$candidates[] = [
			'post_id' => (int) $post_id,
			'score' => (int) $score,
			'timestamp' => (int) ($entry['timestamp'] ?? 0),
		];
	}

	usort($candidates, static function (array $a, array $b): int {
		if ($a['score'] === $b['score']) {
			return $b['timestamp'] <=> $a['timestamp'];
		}
		return $b['score'] <=> $a['score'];
	});

	return array_slice(array_map(static fn(array $row): int => (int) $row['post_id'], $candidates), 0, $limit);
}

function europulse_get_localized_terms(int $post_id, string $taxonomy = 'category'): array {
	if ($taxonomy === 'category') {
		$primary_slug = trim((string) get_post_meta($post_id, '_epv2_primary_category', true));

		if ($primary_slug !== '' && class_exists('EPV2_Taxonomy_Map')) {
			$lang = europulse_current_lang();
			$mapped = EPV2_Taxonomy_Map::map($primary_slug, $lang);
			$term_id = (int) ($mapped['term_id'] ?? 0);

			if ($term_id > 0) {
				$primary_term = get_term($term_id, $taxonomy);

				if ($primary_term && ! is_wp_error($primary_term)) {
					return [$primary_term];
				}
			}
		}
	}

	$terms = get_the_terms($post_id, $taxonomy);

	if (! is_array($terms) || $terms === []) {
		return [];
	}

	$localized_terms = [];
	$lang = europulse_current_lang();

	foreach ($terms as $term) {
		$link_term = $term;

		if (function_exists('pll_get_term')) {
			$translated_term_id = (int) pll_get_term((int) $term->term_id, $lang);

			if ($translated_term_id > 0) {
				$maybe_term = get_term($translated_term_id, $taxonomy);

				if ($maybe_term && ! is_wp_error($maybe_term)) {
					$link_term = $maybe_term;
				}
			}
		}

		$localized_terms[] = $link_term;
	}

	return $localized_terms;
}

function europulse_category_archive_url($term, string $lang = ''): string {
	$term_obj = null;
	if ($term instanceof WP_Term) {
		$term_obj = $term;
	} elseif (is_string($term)) {
		$slug = sanitize_title($term);
		if ($slug !== '' && class_exists('EPV2_Taxonomy_Map')) {
			$mapped = EPV2_Taxonomy_Map::map($slug, $lang !== '' ? $lang : 'de');
			$mapped_id = (int) ($mapped['term_id'] ?? 0);
			if ($mapped_id > 0) {
				$maybe_term = get_term($mapped_id, 'category');
				if ($maybe_term && ! is_wp_error($maybe_term)) {
					$term_obj = $maybe_term;
				}
			}
		}

		if (! $term_obj) {
			$maybe_term = get_term_by('slug', $slug, 'category');
			if ($maybe_term && ! is_wp_error($maybe_term)) {
				$term_obj = $maybe_term;
			}
		}
	} else {
		$term_obj = get_term((int) $term, 'category');
	}

	if (! $term_obj || is_wp_error($term_obj) || $term_obj->taxonomy !== 'category') {
		return '';
	}

	if ($lang === '') {
		$lang = europulse_current_lang();
	}
	if ($lang === '') {
		$lang = 'de';
	}

	if (function_exists('pll_get_term')) {
		$translated_id = (int) (pll_get_term((int) $term_obj->term_id, $lang) ?: 0);
		if ($translated_id > 0) {
			$maybe_term = get_term($translated_id, 'category');
			if ($maybe_term && ! is_wp_error($maybe_term)) {
				$term_obj = $maybe_term;
			}
		}
	}

	$real_link = get_term_link($term_obj);
	if (is_string($real_link) && $real_link !== '' && ! is_wp_error($real_link)) {
		return $real_link;
	}

	$permalink_structure = (string) get_option('permalink_structure', '');
	if ($permalink_structure === '') {
		$url = add_query_arg('cat', (int) $term_obj->term_id, home_url('/'));
		if ($lang !== 'de') {
			$url = add_query_arg('lang', $lang, $url);
		}
		return $url;
	}

	$base = trim((string) get_option('category_base', 'category'), '/');
	if ($base === '') {
		$base = 'category';
	}

	$url = home_url('/' . $base . '/' . rawurlencode($term_obj->slug) . '/');
	if ($lang !== 'de') {
		$url = add_query_arg('lang', $lang, $url);
	}

	return $url;
}

function europulse_get_term_links_html(int $post_id, string $taxonomy = 'category'): string {
	$terms = europulse_get_localized_terms($post_id, $taxonomy);

	if ($terms === []) {
		return '';
	}

	$items = [];
	$lang = europulse_current_lang();

	foreach ($terms as $term) {
		if ($taxonomy === 'category') {
			$link = europulse_category_archive_url($term, $lang);
		} else {
			$link = get_term_link($term);
			if (is_wp_error($link)) {
				continue;
			}
		}
		if (! is_string($link) || $link === '') {
			continue;
		}

		$items[] = '<span class="europulse-term-separator" aria-hidden="true">•</span>' . sprintf(
			'<a class="europulse-term-link" href="%s" rel="tag">%s</a>',
			esc_url($link),
			esc_html($term->name)
		);
	}

	if ($items === []) {
		return '';
	}

	return '<span class="europulse-term-links">' . implode('', $items) . '</span>';
}

function europulse_get_term_names_text(int $post_id, string $taxonomy = 'category'): string {
	$terms = europulse_get_localized_terms($post_id, $taxonomy);

	if ($terms === []) {
		return '';
	}

	$items = [];

	foreach ($terms as $term) {
		$items[] = '• ' . $term->name;
	}

	return implode(' ', $items);
}

function europulse_render_story_meta(int $post_id): string {
	$date = europulse_format_post_date($post_id);
	$parts = ['<span>' . esc_html($date) . '</span>'];
	$term_links = europulse_get_term_links_html($post_id, 'category');

	if ($term_links !== '') {
		$parts[] = $term_links;
	}

	return '<div class="europulse-story-meta">' . implode('', $parts) . '</div>';
}

function europulse_trim_text(string $text, int $limit = 62): string {
	$text = trim(wp_strip_all_tags($text));

	if ($text === '') {
		return '';
	}

	if (function_exists('mb_strimwidth')) {
		return mb_strimwidth($text, 0, $limit, '…', 'UTF-8');
	}

	if (strlen($text) <= $limit) {
		return $text;
	}

	return rtrim(substr($text, 0, max(0, $limit - 1))) . '…';
}

function europulse_trim_text_plain(string $text, int $limit = 62): string {
	$text = trim(wp_strip_all_tags($text));

	if ($text === '') {
		return '';
	}

	$strlen = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

	if ($strlen <= $limit) {
		return $text;
	}

	$cut = function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
	$cut = preg_replace('/\s+\S*$/u', '', $cut) ?: $cut;

	return rtrim($cut, " \t\n\r\0\x0B,.;:-");
}

function europulse_finalize_sentence(string $text): string {
	$text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));

	if ($text === '') {
		return '';
	}

	// Убираем атрибуцию источника из карточного текста — ведущую
	// («Wie X berichtet,»), хвостовую («..., повідомляє X.») и встречающуюся
	// в середине, в обоих случаях оставляя саму новость. Это последний
	// этап перед финализацией предложения, поэтому работает даже на
	// результатах sentence-buffer / clause-cut.
	if (function_exists('europulse_strip_leading_source_attribution')) {
		$text = europulse_strip_leading_source_attribution($text);
	}

	$text = rtrim($text, " \t\n\r\0\x0B,;:-—–");

	// Срезаем «висящие» хвосты: союзы / предлоги / артикли / служебные
	// слова, после которых должно идти существительное, но было обрезано
	// сервером. Без этого получаются мусорные «після суттєвих.»,
	// «without the.», «Альтмайєра, але.» — союз/предлог + точка.
	// Перебираем итеративно: пока последнее слово в стоп-листе — снимаем
	// его, пока не найдём слово с самостоятельным смыслом.
	$stoplist_patterns = [
		// конъюнкции / союзы
		'і','та','и','але','однак','проте','хоча','тому','щоб','якщо','as','and','but','or','either','neither','nor','however','although','though','while','because','doch','aber','jedoch','sondern','denn','weil','während','wenn','dennoch','trotzdem','zudem','auch','noch','dann','also',
		// предлоги (UK)
		'без','про','на','в','у','за','для','о','до','після','при','під','над','поки','пока','щодо','як','с','з','о','об','зі','через','проти','біля','навколо','серед','між','крім','поза','завдяки','внаслідок','щодо','протягом','упродовж','усередині','навпроти','стосовно',
		// предлоги (RU)
		'из','со','во','от','около','среди','между','против','через','вокруг','внутри','снаружи','благодаря','вследствие','помимо','кроме','вместо','посреди','напротив','согласно',
		'without','after','before','over','under','by','with','to','from','on','in','at','of','for','about','against','toward','through','between','among','near','around','via',
		'ohne','durch','für','gegen','nach','um','von','zu','aus','bei','mit','seit','über','unter','vor','hinter','zwischen','wegen','trotz','statt',
		// артикли / детерминаторы
		'the','a','an','der','die','das','den','dem','des','ein','eine','einen','einem','eines','einer',
		// местоимения, висящие
		'this','that','these','those','it','he','she','they','we','his','her','their','its','dieser','diese','dieses','jenes','dass',
		// частицы
		'just','only','even','very','quite','rather','also','then','still','already','yet','hier','dort','jetzt','erst',
	];
	$stoplist_re = '/(\s|[,;:—–-])(' . implode('|', array_map('preg_quote', $stoplist_patterns)) . ')$/iu';

	$was_cut = false;
	$max_iterations = 5;
	while ($max_iterations-- > 0) {
		$before = $text;
		$text = preg_replace($stoplist_re, '', $text);
		$text = rtrim($text, " \t\n\r\0\x0B,;:-—–");
		if ($text === $before) {
			break;
		}
		$was_cut = true;
	}

	// Если предпоследнее слово — предлог («після», «without», «für»…),
	// а после него только одно «висящее» слово (прилагательное /
	// определение / число) без существительного — срезаем обе единицы.
	// Это ловит «після суттєвих.» / «without the usual.» / «für die.» —
	// фрагменты предложения, не несущие смысл.
	$prepositions_only = [
		'без','про','на','в','у','за','для','о','до','після','при','під','над','щодо','як','с','з','об','зі','через','проти','біля','навколо','серед','між','крім','поза','завдяки','внаслідок','протягом','упродовж','усередині','навпроти','стосовно',
		'из','со','во','от','около','среди','между','против','через','вокруг','внутри','снаружи','благодаря','вследствие','помимо','кроме','вместо','посреди','напротив','согласно',
		'without','after','before','over','under','by','with','to','from','on','in','at','of','for','about','against','toward','through','between','among','near','around','via','upon','onto','into','beside','beyond','during','since','until','despite',
		'ohne','durch','für','gegen','nach','um','von','zu','aus','bei','mit','seit','über','unter','vor','hinter','zwischen','wegen','trotz','statt','während','innerhalb','ausserhalb','außerhalb',
	];
	// Не режем если последнее слово начинается с заглавной буквы —
	// это, скорее всего, имя собственное (страна / город / человек /
	// организация), которое НУЖНО оставить: «в Англії», «für Moldau»,
	// «with Trump», «in Berlin». Регекс отрицательного захвата на
	// uppercase: \p{Lu} в начале последнего токена.
	$dangling_pp_re = '/(\s|[,;:—–-])(' . implode('|', array_map('preg_quote', $prepositions_only)) . ')\s+(?!\p{Lu})\S{1,30}$/iu';
	// Чисто висящий предлог в самом конце фразы — без объекта вообще.
	// Ловит «...у Римі проти.» / «against.» / «für.» — обрезанный
	// сервер не успел дописать существительное. Срезаем сам предлог.
	$trailing_prep_re = '/(\s|[,;:—–-])(' . implode('|', array_map('preg_quote', $prepositions_only)) . ')$/iu';
	$max_pp_iterations = 4;
	while ($max_pp_iterations-- > 0) {
		$before_pp = $text;
		$text = preg_replace($dangling_pp_re, '', $text);
		$text = rtrim($text, " \t\n\r\0\x0B,;:-—–");
		$text = preg_replace($trailing_prep_re, '', $text);
		$text = rtrim($text, " \t\n\r\0\x0B,;:-—–");
		if ($text === $before_pp) {
			break;
		}
		$was_cut = true;
	}

	if ($text === '') {
		return '';
	}

	if ($was_cut) {
		// Сняли висящий стоп-хвост — многоточие как сигнал
		// «продолжение на странице».
		if (! preg_match('/[.!?…]$/u', $text)) {
			$text .= '…';
		}
	} else {
		// Не вмешивались — если терминатор отсутствует, ставим точку
		// как раньше.
		if (! preg_match('/[.!?…]$/u', $text)) {
			$text .= '.';
		}
	}

	return $text;
}

function europulse_strip_leading_source_attribution(string $text): string {
	// Языкозависимое поведение:
	// — German V2-word order ломается при удалении вводной клаузы
	//   («Wie X berichtet, hat Y» → «Hat Y» — грамматически дикий обрывок).
	//   Поэтому для DE снимаем только если остаток начинается с заглавной,
	//   что сигнализирует subject-first порядок («Das Parlament hat...»).
	// — UK / RU / EN — flat word order, после снятия атрибуции остаток
	//   читается естественно с маленькой буквы; мы её капитализируем.
	$strip_patterns = [
		// [regex, allow_verb_initial]
		// German — атрибуция всегда снимается, остаток капитализируется.
		// V2-порядок («Wie X berichtet, hat Y…» → «Hat Y…») остаётся
		// чуть инвертированным, но в карточке это допустимо: пользователь
		// явно сказал «не нужно на источник ссылаться» в карточках.
		// [^,]{1,80}? — широкое имя источника, ловит «24tv», «Frankfurter
		// Allgemeine Zeitung (FAZ)», «t-online.de» итд.
		['/^\s*Wie\s+(?:die\s+|der\s+|das\s+)?[^,]{1,80}?\s+(?:berichtet|meldet|mitteilt|schreibt|erkl[äa]rt)\s*,\s*/iu', true],
		['/^\s*Nach\s+Angaben\s+(?:von|der|des)\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*Nach\s+Informationen\s+(?:von|der|des)\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*Laut\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*[\p{Lu}][^\s,]{1,40}\s+zufolge\s*,\s*/iu', true],
		// Ukrainian / Russian — capitalize remainder. Расширенный source-pattern.
		['/^\s*За\s+повідомленням(?:и)?\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*Як\s+повідомля[єют][\p{Ll}]*\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*Як\s+пише\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*Як\s+(?:пише|зазначає|зазначив|повідомляє|інформує)\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*За\s+(?:даними|словами|інформацією|повідомленням)\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*Повідомля[єют][\p{Ll}]*\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*Інформує\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*Пише\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*По\s+(?:сообщению|данным|информации)\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*(?:Сообщает|Передаёт|Передает)\s+[^,]{1,80}?\s*,\s*/iu', true],
		// English — capitalize remainder (often already starts with proper noun)
		['/^\s*According\s+to\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*As\s+(?:[^,]{1,40}?\s+)?reports?\s*,\s*/iu', true],
		['/^\s*As\s+reported\s+by\s+[^,]{1,80}?\s*,\s*/iu', true],
		['/^\s*[A-Z][^,\s]{1,40}\s+reports?\s*,\s*/iu', true],
		['/^\s*Per\s+[^,]{1,80}?\s*,\s*/iu', true],
	];
	foreach ($strip_patterns as $row) {
		[$re, $allow_lowercase_remainder] = $row;
		$candidate = preg_replace($re, '', $text);
		if (! is_string($candidate) || $candidate === $text) {
			continue;
		}
		$candidate = trim($candidate);
		if ($candidate === '' || mb_strlen($candidate, 'UTF-8') < 40) {
			continue;
		}
		$first_char = mb_substr($candidate, 0, 1, 'UTF-8');
		$first_upper = mb_strtoupper($first_char, 'UTF-8');
		if ($first_char === $first_upper) {
			$text = $candidate;
			break;
		}
		// Остаток начинается со строчной буквы.
		if (! $allow_lowercase_remainder) {
			// Для немецкого это значит V2-инверсия: остаток с глагола.
			// Не режем — оставляем атрибуцию чтобы не получить «Hat das...»
			continue;
		}
		// Для UK/RU/EN капитализируем первую букву остатка.
		$text = $first_upper . mb_substr($candidate, 1, null, 'UTF-8');
		break;
	}

	// Хвостовая атрибуция: «..., повідомляє X.» / «..., as X reports.» —
	// тоже belongs в body, не в карточный hook. Снимаем хвост, восстанавливаем
	// финальную точку (если осталась запятая после хвоста — заменяем на точку).
	$tail_patterns = [
		// Ukrainian / Russian
		'/[\s,;:—–-]+(?:як\s+)?повідомля[єют][\p{Ll}]*\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+за\s+(?:повідомленням(?:и)?|даними|інформацією|словами)\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+пише\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+інформує\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+сообщает\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+по\s+(?:сообщению|данным|информации)\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		// English
		'/[\s,;:—–-]+(?:as\s+)?[\p{Lu}][\p{L}\.\-]{1,30}\s+reports?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+as\s+reported\s+by\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+according\s+to\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		// German
		'/[\s,;:—–-]+wie\s+(?:die\s+|der\s+|das\s+)?[\p{Lu}][\p{L}\.\-\s]{1,40}?\s+(?:berichtet|meldet|mitteilt|schreibt)\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+laut\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+nach\s+Angaben\s+(?:von|der|des)\s+[\p{Lu}][\p{L}\.\-\s]{1,40}?\s*[\.\!\?]?\s*$/u',
		'/[\s,;:—–-]+[\p{Lu}][\p{L}\.\-]{1,30}\s+zufolge\s*[\.\!\?]?\s*$/u',
	];
	foreach ($tail_patterns as $tre) {
		$candidate = preg_replace($tre, '', $text);
		if (! is_string($candidate) || $candidate === $text) {
			continue;
		}
		$candidate = rtrim(trim($candidate), " ,.;:—–-");
		if ($candidate === '' || mb_strlen($candidate, 'UTF-8') < 40) {
			continue;
		}
		// Восстанавливаем финальную точку (карточка ждёт законченного
		// предложения; finalize_sentence ниже всё равно поправит, но
		// быстрее иметь нормальный терминатор сразу).
		if (! preg_match('/[\.\!\?…]$/u', $candidate)) {
			$candidate .= '.';
		}
		$text = $candidate;
		break;
	}

	// Сломанные даты типа «am 8. Chisinau» — AI выкинул месяц, осталась
	// «am 8.» перед произвольным словом. Удаляем сам артефакт «am \d+\. »
	// и оставляем дальше существительное. То же для UK «8 травня» (если
	// порядок дней-месяца перепутан) и EN «on 8.».
	$text = preg_replace(
		'/\b(?:am|den|im)\s+\d{1,2}\.\s+(?!Januar|Februar|März|Maerz|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)/u',
		'',
		$text
	);
	$text = preg_replace(
		'/\bon\s+\d{1,2}\.\s+(?!January|February|March|April|May|June|July|August|September|October|November|December)/u',
		'',
		$text
	);
	$text = trim((string) $text);
	if ($text === '') {
		return '';
	}

	return $text;
}


function europulse_context_excerpt(int $post_id, string $context = 'default'): string {
	// AI-сгенерированный card_lead — выделенный лид-магнит для карточек.
	// Worker валидирует длину, грамматику и закрытость предложения; если
	// поле непустое — рендерим как есть, без обрезки и finalize. Это
	// архитектурный switch с «вырезать предложение из лида» (легаси) на
	// «AI генерит правильный текст под карточку» (новая модель). Старые
	// посты не имеют этого мета-поля и продолжают рендериться по легаси.
	$card_lead = trim((string) get_post_meta($post_id, '_europulse_card_lead', true));
	if ($card_lead !== '') {
		$card_lead = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($card_lead)));
		if ($card_lead !== '' && function_exists('mb_strlen') && mb_strlen($card_lead, 'UTF-8') >= 40) {
			return $card_lead;
		}
	}

	$source = trim((string) get_the_excerpt($post_id));

	if ($source === '') {
		$source = trim((string) get_post_field('post_content', $post_id));
	}

	$source = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($source)));

	if ($source === '') {
		return '';
	}

	$lang = europulse_current_lang();

		// Лимиты подобраны под фактический визуальный бюджет карточек.
		// CSS даёт КАЖДОМУ excerpt'у фиксированный размер (min-height +
		// max-height + line-clamp), сервер обрезает строго в этот
		// бюджет на границе предложения/клаузы. Кириллица занимает
		// больше места по горизонтали → UK на 15-20% ниже DE/EN.
		//   secondary-card excerpt   (3 строки × 11px,  ~50ch/line)
		//   section-list-excerpt     (2 строки × 11px,  ~45ch/line)
		//   home-latest-excerpt      (3 строки × 10.5px,~50ch/line)
		//   top-slide-excerpt        (3 строки × 12px,  max-w 45ch)
		$limits = [
			'slider' => [
				'de' => 140,
				'uk' => 120,
				'en' => 140,
			],
			'latest' => [
				'de' => 90,
				'uk' => 75,
				'en' => 90,
			],
			'default' => [
				'de' => 135,
				'uk' => 115,
				'en' => 135,
			],
		];

	$context_limits = $limits[$context] ?? $limits['default'];
	$limit = $context_limits[$lang] ?? $context_limits['de'] ?? 180;
	// hard_max — максимум для целого предложения когда его не нужно резать
	// (визуальный CSS line-clamp всё равно покажет "..." если не помещается,
	// зато текст будет грамматически законченный, а не оборванный).
	$hard_max = (int) floor($limit * 1.6);
	// soft_max — расширенный бюджет для клаузы (между запятыми / тире).
	// 1.7× даёт шанс прихватить целую клаузу-мысль вместо обрезки на полуслове.
	$soft_max = (int) floor($limit * 1.7);
	// Не считаем точку после цифры или одиночной заглавной буквы концом
	// предложения: «am 8. Mai», «J. F. Kennedy», «10. Juli», «z. B.» —
	// иначе превращаем дату/аббревиатуру в два псевдо-предложения.
	$sentences = preg_split('/(?<=[.!?])(?<![0-9]\.)(?<!\b\p{Lu}\.)\s+(?=\p{Lu})/u', $source, -1, PREG_SPLIT_NO_EMPTY);

	if (is_array($sentences) && $sentences !== []) {
		$buffer = '';

		foreach ($sentences as $sentence) {
			$candidate = trim($buffer . ' ' . trim($sentence));
			$length = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);

			if ($length > $limit) {
				break;
			}

			$buffer = $candidate;

			if ($length >= (int) floor($limit * 0.68)) {
				break;
			}
		}

		if ($buffer !== '') {
			return europulse_finalize_sentence($buffer);
		}

		// Если первое предложение целиком в пределах hard_max (1.6×limit)
		// — лучше вернуть его полностью, чем резать на клаузе или полуслове.
		// Целое предложение всегда читается лучше любого обрезка.
		$first_sentence = trim($sentences[0]);
		$first_len = function_exists('mb_strlen') ? mb_strlen($first_sentence, 'UTF-8') : strlen($first_sentence);
		if ($first_len <= $hard_max) {
			return europulse_finalize_sentence($first_sentence);
		}

		// Первое предложение слишком длинное. Режем на клаузах (запятая,
		// тире, двоеточие, точка с запятой) с расширенным бюджетом soft_max.
		// Это даёт законченный фрагмент мысли — «лид-магнит», а не обрывок.
		$clauses = preg_split('/\s*[,;:—–]\s+/u', $first_sentence, -1, PREG_SPLIT_NO_EMPTY);

		if (is_array($clauses) && $clauses !== []) {
			$clause_buffer = '';

			foreach ($clauses as $clause) {
				$candidate = trim($clause_buffer === '' ? $clause : $clause_buffer . ', ' . $clause);
				$length = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);

				if ($length > $soft_max) {
					break;
				}

				$clause_buffer = $candidate;
			}

			if ($clause_buffer !== '') {
				return europulse_finalize_sentence($clause_buffer);
			}
		}

		// Крайний случай: ни сентенция, ни клауза не помещаются в бюджет.
		// Режем по словам и доверяем finalize_sentence срезать висящие
		// предлоги / союзы / неполные имена собственные.
		return europulse_finalize_sentence(europulse_trim_text_plain($first_sentence, $hard_max));
	}

	return europulse_finalize_sentence(europulse_trim_text_plain($source, $hard_max));
}

function europulse_title_length_class(string $text): string {
	$length = function_exists('mb_strlen') ? mb_strlen(trim(wp_strip_all_tags($text)), 'UTF-8') : strlen(trim(wp_strip_all_tags($text)));

	if ($length <= 58) {
		return 'europulse-top-slide-title--short';
	}

	if ($length <= 82) {
		return 'europulse-top-slide-title--medium';
	}

	return 'europulse-top-slide-title--long';
}

function europulse_trim_slider_title(string $text): string {
	return trim(wp_strip_all_tags($text));
}

function europulse_shorten_headline_semantic(string $text, int $limit): string {
	$text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));

	if ($text === '') {
		return '';
	}

	$length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
	if ($length <= $limit) {
		return $text;
	}

	$candidates = [];
	$parts = preg_split('/\s*[:;—–-]\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	if (count($parts) > 1) {
		$candidates[] = trim((string) $parts[0]);
	}

	$comma_parts = preg_split('/\s*,\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	if (count($comma_parts) > 1) {
		$candidates[] = trim((string) $comma_parts[0]);
	}

	$sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	if ($sentences !== []) {
		$candidates[] = trim((string) $sentences[0]);
	}

	foreach ($candidates as $candidate) {
		$candidate_length = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);
		if ($candidate !== '' && $candidate_length <= $limit && $candidate_length >= (int) floor($limit * 0.52)) {
			return rtrim($candidate, " \t\n\r\0\x0B,.;:-");
		}
	}

	return europulse_trim_text_plain($text, $limit);
}

function europulse_trim_title_for_context(string $text, string $context = 'default'): string {
	$text = trim(wp_strip_all_tags($text));

	if ($text === '') {
		return '';
	}

	$lang = europulse_current_lang();
	$limits = [
		'latest' => ['de' => 56, 'uk' => 50, 'en' => 58],
		'widget' => ['de' => 58, 'uk' => 52, 'en' => 60],
		'most_read' => ['de' => 58, 'uk' => 52, 'en' => 60],
		'compact' => ['de' => 66, 'uk' => 60, 'en' => 70],
		'secondary' => ['de' => 68, 'uk' => 62, 'en' => 72],
		'section_lead' => ['de' => 66, 'uk' => 60, 'en' => 70],
		'section_list' => ['de' => 62, 'uk' => 56, 'en' => 66],
		'default' => ['de' => 64, 'uk' => 58, 'en' => 68],
	];

	$context_limits = $limits[$context] ?? $limits['default'];
	$limit = (int) ($context_limits[$lang] ?? $context_limits['de'] ?? 64);

	return europulse_shorten_headline_semantic($text, $limit);
}

function europulse_context_title(int $post_id, string $context = 'default'): string {
	if ($context === 'slider') {
		return europulse_slider_headline($post_id);
	}

	return trim(wp_strip_all_tags((string) get_the_title($post_id)));
}

function europulse_finalize_headline(string $text): string {
	$text = trim(wp_strip_all_tags($text));

	if ($text === '') {
		return '';
	}

	if (! preg_match('/[.!?…]$/u', $text)) {
		$text .= '.';
	}

	return $text;
}

function europulse_slider_headline(int $post_id): string {
	return europulse_finalize_headline(get_the_title($post_id));
}

function europulse_render_lang_switcher() {
	if (! function_exists('pll_the_languages')) {
		return '<span>DE / UK / EN</span>';
	}

	$languages = pll_the_languages([
		'raw' => 1,
		'hide_if_empty' => 0,
		'hide_if_no_translation' => 0,
	]);

	if (! is_array($languages) || empty($languages)) {
		return '<span>DE / UK / EN</span>';
	}

	$order = ['de' => 1, 'uk' => 2, 'en' => 3];
	uasort($languages, static function ($a, $b) use ($order) {
		$a_order = $order[$a['slug']] ?? 99;
		$b_order = $order[$b['slug']] ?? 99;

		return $a_order <=> $b_order;
	});

	$items = [];

	foreach ($languages as $language) {
		$label = europulse_lang_label($language['slug']);

		if (! empty($language['current_lang'])) {
			$items[] = sprintf(
				'<span class="is-current" aria-current="true">%s</span>',
				esc_html($label)
			);
			continue;
		}

		$items[] = sprintf(
			'<a href="%s" hreflang="%s">%s</a>',
			esc_url($language['url']),
			esc_attr($language['slug']),
			esc_html($label)
		);
	}

	return implode(' <span class="europulse-utility-sep">/</span> ', $items);
}

function europulse_render_ad_slot($slot, $class = '') {
	if (! europulse_ads_enabled()) {
		return '';
	}

	$class_attr = trim('europulse-ad-slot ' . $class);
	$uploads = wp_upload_dir();
	$base_media_url = trailingslashit($uploads['baseurl']) . '2026/03/';
	$creative_map = [
		'header-leaderboard' => [
			'title' => 'NordWest Mobility Summit',
			'eyebrow' => europulse_t('advertising_label'),
			'text' => '24-26 April · Muenchen',
			'cta' => 'Tickets & Programm',
			'image' => $base_media_url . 'Olympic_Stadium_Munich_-_Rows_of_Seats2C_April_2019_-04-scaled.jpg',
			'headline' => 'Urban mobility for cities and logistics',
			'button' => europulse_t('ad_button_bookmark'),
			'badge' => '24-26 April',
			'media_class' => 'europulse-ad-media--hero-photo',
			'variant' => 'leaderboard static',
			'url' => 'https://www.google.com/',
		],
		'sidebar-rail' => [
			'title' => 'Stadtfenster Apartments',
			'eyebrow' => europulse_t('advertising_label'),
			'text' => europulse_t('ad_sidebar_text'),
			'cta' => 'Verfuegbarkeit pruefen',
			'image' => $base_media_url . 'Berlin-Charlottenburg_Theater_des_Westens_05-2014-scaled.jpg',
			'headline' => europulse_t('ad_sidebar_headline'),
			'button' => europulse_t('ad_button_contact'),
			'badge' => europulse_t('ad_sidebar_badge'),
			'media_class' => 'europulse-ad-media--rail-photo',
			'variant' => 'rail static',
			'url' => 'https://www.google.com/',
		],
		'article-inline-1' => [
			'title' => 'RheinHaus Coworking',
			'eyebrow' => europulse_t('advertising_sponsored'),
			'text' => 'Muenchen & Berlin · Tagespaesse',
			'cta' => 'Standorte ansehen',
			'image' => $base_media_url . 'BC3BCro_der_Workcamp-Organisation_IBG_e.V._in_Stuttgart.jpg',
			'headline' => 'Studios, desks and meeting rooms for small teams',
			'button' => europulse_t('ad_button_try'),
			'badge' => 'Sponsored',
			'media_class' => 'europulse-ad-media--inline-photo',
			'variant' => 'inline static',
			'url' => 'https://www.google.com/',
		],
		'article-inline-2' => [
			'title' => 'VoltGrid Business Energy',
			'eyebrow' => europulse_t('advertising_label'),
			'text' => 'Live dashboard · motion creative',
			'cta' => 'Live demo anfragen',
			'image' => $base_media_url . 'Seat_of_the_European_Central_Bank_and_Frankfurt_Skyline_at_dawn_20150422_1-scaled.jpg',
			'image_2' => $base_media_url . 'Hamburg-Harbor-by-eschenzweig-scaled.jpg',
			'image_3' => $base_media_url . 'Reichstag_pano-scaled.jpg',
			'headline' => 'Energy and charging systems for growing sites',
			'button' => europulse_t('ad_button_demo'),
			'badge' => 'Live motion',
			'media_class' => 'europulse-ad-media--motion-photo',
			'variant' => 'inline animated',
			'url' => 'https://www.google.com/',
		],
		'homepage-between-sections' => [
			'title' => 'Leitwerk Steuerberatung',
			'eyebrow' => europulse_t('advertising_label'),
			'text' => 'GmbH · Vereine · Selbststaendige',
			'cta' => 'Beratung vereinbaren',
			'image' => $base_media_url . 'European_Parliament_Strasbourg_Hemicycle_-_Diliff-2048x1157.jpg',
			'headline' => 'Tax and bookkeeping support with quick response',
			'button' => europulse_t('ad_button_talk'),
			'badge' => 'Remote & vor Ort',
			'media_class' => 'europulse-ad-media--wide-photo',
			'variant' => 'between static',
			'url' => 'https://www.google.com/',
		],
	];
	$creative = $creative_map[$slot] ?? [
		'title' => europulse_t('advertising_demo'),
		'eyebrow' => europulse_t('advertising_label'),
		'text' => sprintf(europulse_t('advertising_slot'), $slot),
		'cta' => europulse_t('discover_more'),
		'image' => '',
		'variant' => 'generic',
		'url' => 'https://www.google.com/',
	];

	$creative_url_host = strtolower((string) wp_parse_url((string) ($creative['url'] ?? ''), PHP_URL_HOST));
	if ($creative_url_host === 'www.google.com' || $creative_url_host === 'google.com') {
		return '';
	}

	$is_motion = str_contains((string) $creative['variant'], 'animated');

	if (! empty($creative['image'])) {
		$image_path = str_replace(content_url(), WP_CONTENT_URL ? WP_CONTENT_DIR : ABSPATH . 'wp-content', $creative['image']);

		if (is_string($image_path) && file_exists($image_path)) {
			$creative['image'] = add_query_arg('v', (string) filemtime($image_path), $creative['image']);
		}
	}

	$style = '';
	if (! empty($creative['image'])) {
		$style .= '--ad-image:url(' . esc_url($creative['image']) . ');';
	}
	if (! empty($creative['image_2'])) {
		$image_path = str_replace(content_url(), WP_CONTENT_URL ? WP_CONTENT_DIR : ABSPATH . 'wp-content', $creative['image_2']);
		if (is_string($image_path) && file_exists($image_path)) {
			$creative['image_2'] = add_query_arg('v', (string) filemtime($image_path), $creative['image_2']);
		}
		$style .= '--ad-image-2:url(' . esc_url($creative['image_2']) . ');';
	}
	if (! empty($creative['image_3'])) {
		$image_path = str_replace(content_url(), WP_CONTENT_URL ? WP_CONTENT_DIR : ABSPATH . 'wp-content', $creative['image_3']);
		if (is_string($image_path) && file_exists($image_path)) {
			$creative['image_3'] = add_query_arg('v', (string) filemtime($image_path), $creative['image_3']);
		}
		$style .= '--ad-image-3:url(' . esc_url($creative['image_3']) . ');';
	}

	$media_classes = 'europulse-ad-media ' . ($creative['media_class'] ?? 'europulse-ad-media--wide-photo');

	$overlay = '<span class="europulse-ad-overlay-copy">'
		. '<span class="europulse-ad-badge">' . esc_html($creative['badge'] ?? '') . '</span>'
		. '<span class="europulse-ad-headline">' . esc_html($creative['title']) . '</span>'
		. '<span class="europulse-ad-subline">' . esc_html($creative['headline'] ?? $creative['text']) . '</span>'
		. '<span class="europulse-ad-button">' . esc_html($creative['button'] ?? $creative['cta']) . '</span>'
		. '</span>';

	if ($is_motion) {
		$media = '<span class="' . esc_attr($media_classes) . '" style="' . esc_attr($style) . '" aria-hidden="true">'
			. '<span class="europulse-ad-frame europulse-ad-frame--one"></span>'
			. '<span class="europulse-ad-frame europulse-ad-frame--two"></span>'
			. '<span class="europulse-ad-frame europulse-ad-frame--three"></span>'
			. '<span class="europulse-ad-motion-grid"></span>'
			. $overlay
			. '</span>';
	} else {
		$media = '<span class="' . esc_attr($media_classes) . '" style="' . esc_attr($style) . '" aria-hidden="true">'
			. $overlay
			. '</span>';
	}

	return sprintf(
		'<div class="%1$s" data-slot="%2$s" aria-label="%3$s"><a class="europulse-ad-card europulse-ad-card--%4$s" href="%5$s" target="_blank" rel="noopener noreferrer sponsored"><div class="europulse-ad-meta"><span class="europulse-ad-kicker">%6$s</span><span class="europulse-ad-brand">%7$s</span></div>%8$s</a></div>',
		esc_attr($class_attr),
		esc_attr($slot),
		esc_attr(sprintf(europulse_t('advertising_slot'), $slot)),
		esc_attr($creative['variant']),
		esc_url($creative['url']),
		esc_html($creative['eyebrow']),
		esc_html($creative['title']),
		$media
	);
}

// The Blocksy desktop "More" overflow item becomes especially ugly with long
// Ukrainian labels and creates a fake extra block in the header. Keep the
// navigation flat and let our own CSS/layout handle it instead.
add_filter('blocksy:header:menu:has-responsive-desktop-menu', '__return_false');
