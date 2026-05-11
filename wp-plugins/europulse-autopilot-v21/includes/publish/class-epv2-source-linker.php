<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * EPV2_Source_Linker
 *
 * Превращает текстовую атрибуцию в карданный гипертекст. Цель — юридически
 * корректное цитирование (§ 51 UrhG / Fair-use): первое упоминание источника
 * в теле статьи получает <a href=ORIGINAL_URL rel="external nofollow noopener">.
 * Повторные упоминания того же источника остаются plain text.
 *
 * Примеры паттернов которые ловятся:
 *   DE:  «Wie [die] BBC berichtet, …»  → «Wie die <a href=…>BBC</a> berichtet, …»
 *        «laut Spiegel …»                 → «laut <a href=…>Spiegel</a> …»
 *        «nach Angaben von Reuters …»     → «nach Angaben von <a href=…>Reuters</a> …»
 *        «BBC zufolge …»                  → «<a href=…>BBC</a> zufolge …»
 *   UK:  «За повідомленням BBC, …»       → «За повідомленням <a href=…>BBC</a>, …»
 *        «як повідомляє Reuters»          → «як повідомляє <a href=…>Reuters</a>»
 *   EN:  «According to The Guardian, …»  → «According to <a href=…>The Guardian</a>, …»
 *        «BBC reports …»                  → «<a href=…>BBC</a> reports …»
 *
 * Архитектура:
 *   1. Caller собирает массив sources: [{url, name, domain}, ...].
 *      Первый элемент — primary, дальше siblings.
 *   2. Для каждого source строится список aliases (BBC → ["BBC", "BBC News",
 *      "die BBC", "BBC.com"]) на базе name + domain.
 *   3. По строке исчём паттерны атрибуции; в каждом совпадении проверяем
 *      входит ли в текст совпадения один из aliases. Если да — оборачиваем
 *      его в <a>...</a>. Линкуется только ПЕРВОЕ срабатывание per source.
 *   4. Skip if matched span уже внутри <a>...</a> (anti-double-link).
 *
 * Сохраняется в БД (post_content). Не модифицирует AI-output prompts —
 * чистый PHP post-processing на момент wp_insert_post / wp_update_post.
 */
final class EPV2_Source_Linker {

	private const REL_ATTRS = 'external nofollow noopener';

	/**
	 * Главный entrypoint.
	 *
	 * @param string $html   Контент в HTML формате (post_content).
	 * @param array  $sources Список источников: каждый — assoc array с
	 *                        ключами url (string), name (string),
	 *                        domain (string optional).
	 *                        Первый — primary, дальше siblings.
	 * @return string         Контент с inline-ссылками на источники.
	 */
	public static function link_attributions(string $html, array $sources): string {
		if ($html === '' || empty($sources)) {
			return $html;
		}
		// Подготавливаем нормализованный набор источников + aliases.
		$prepared = [];
		foreach ($sources as $src) {
			$url = trim((string) ($src['url'] ?? ''));
			$name = trim((string) ($src['name'] ?? ''));
			if ($url === '' || $name === '') {
				continue;
			}
			$domain = trim((string) ($src['domain'] ?? ''));
			if ($domain === '') {
				$h = wp_parse_url($url, PHP_URL_HOST);
				$domain = is_string($h) ? preg_replace('/^www\./i', '', $h) : '';
			}
			$aliases = self::build_aliases($name, $domain);
			$prepared[] = [
				'url' => $url,
				'name' => $name,
				'domain' => $domain,
				'aliases' => $aliases,
				'linked' => false, // флаг — уже линковали один раз?
			];
		}
		if ($prepared === []) {
			return $html;
		}
		// Ищем атрибутные конструкции и пытаемся слинковать source-name
		// внутри них.
		return self::process_html($html, $prepared);
	}

	/**
	 * Собрать массив sources для конкретного queue item / post.
	 *
	 * @param object|null $item Queue row (для primary URL).
	 * @param array       $payload Декодированный ai_payload.
	 * @return array        Готовый массив для link_attributions().
	 */
	public static function sources_for_item(?object $item, array $payload): array {
		$sources = [];
		// Primary
		$primary_url = trim((string) ($item->original_url ?? ''));
		$primary_name = self::resolve_source_name((int) ($item->source_id ?? 0), $primary_url);
		if ($primary_url !== '' && $primary_name !== '') {
			$h = wp_parse_url($primary_url, PHP_URL_HOST);
			$sources[] = [
				'url' => $primary_url,
				'name' => $primary_name,
				'domain' => is_string($h) ? preg_replace('/^www\./i', '', $h) : '',
			];
		}
		// Siblings из source_dossier.related[] (наш sibling-enrichment +
		// dropped_siblings)
		$related = $payload['_meta']['source_dossier']['related'] ?? [];
		if (is_array($related)) {
			foreach ($related as $r) {
				if (! is_array($r)) {
					continue;
				}
				$url = trim((string) ($r['url'] ?? ''));
				$name = trim((string) ($r['source_name'] ?? $r['title'] ?? ''));
				if ($url === '' || $name === '') {
					continue;
				}
				$domain = trim((string) ($r['domain'] ?? ''));
				if ($domain === '') {
					$h = wp_parse_url($url, PHP_URL_HOST);
					$domain = is_string($h) ? preg_replace('/^www\./i', '', $h) : '';
				}
				$sources[] = ['url' => $url, 'name' => $name, 'domain' => $domain];
			}
		}
		// Supporting URLs от worker'а (`_search_supporting_sources_rich`).
		// Имя обычно пустое — извлекаем из домена. AI цитирует их как «n-tv»,
		// «ZEIT», «tagesschau» (capitalized без TLD).
		$supporting = $payload['_meta']['source_dossier']['supporting'] ?? $payload['source_dossier']['supporting'] ?? [];
		if (is_array($supporting)) {
			foreach ($supporting as $r) {
				if (! is_array($r)) {
					continue;
				}
				$url = trim((string) ($r['url'] ?? ''));
				if ($url === '') {
					continue;
				}
				$h = wp_parse_url($url, PHP_URL_HOST);
				$domain = is_string($h) ? preg_replace('/^www\./i', '', $h) : '';
				if ($domain === '') {
					continue;
				}
				$name = trim((string) ($r['title'] ?? ''));
				if ($name === '') {
					// Имя из домена: "n-tv.de" → "n-tv"; "tagesschau.de" → "tagesschau";
					// "theguardian.com" → "Guardian" (capitalize первый non-article).
					$name = self::derive_name_from_domain($domain);
				}
				if ($name === '') {
					continue;
				}
				$sources[] = ['url' => $url, 'name' => $name, 'domain' => $domain];
			}
		}
		// Dedupe by domain — иначе primary и sibling от того же издателя
		// (если такое случится) дублируют link.
		$seen_domain = [];
		$out = [];
		foreach ($sources as $s) {
			$d = (string) ($s['domain'] ?? '');
			if ($d !== '' && isset($seen_domain[$d])) {
				continue;
			}
			$seen_domain[$d] = true;
			$out[] = $s;
		}
		return $out;
	}

	/**
	 * Расщепить HTML на text-pieces вне `<a>` тегов и линковать только в них.
	 * Это защита от двойного оборачивания.
	 */
	private static function process_html(string $html, array &$sources): string {
		// Разбиваем строку на сегменты: либо целиком `<a ...>...</a>` (skip),
		// либо обычный текст (process).
		$pattern = '/<a\b[^>]*>[\s\S]*?<\/a>/i';
		$parts = preg_split($pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);
		if (! is_array($parts)) {
			return $html;
		}
		preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
		// Простейший подход: разбиваем по `<a>...</a>` явно.
		$tokens = preg_split('/(<a\b[^>]*>[\s\S]*?<\/a>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (! is_array($tokens) || $tokens === []) {
			return $html;
		}
		$rebuilt = '';
		foreach ($tokens as $token) {
			if (preg_match('/^<a\b/i', $token)) {
				$rebuilt .= $token; // anchor пропускаем без обработки
				continue;
			}
			$rebuilt .= self::process_text_segment($token, $sources);
		}
		return $rebuilt;
	}

	/**
	 * Process a non-<a> text/HTML segment: ищем атрибутные конструкции и
	 * оборачиваем имя источника.
	 */
	private static function process_text_segment(string $text, array &$sources): string {
		if ($text === '') {
			return $text;
		}
		// Для каждого source в порядке списка, пытаемся найти его alias в
		// атрибутном контексте. Линкуем только первое попадание per source
		// (linked-флаг). Несколько разных источников могут быть линкнуты
		// в одном сегменте — это OK.
		foreach ($sources as &$src) {
			if (! empty($src['linked'])) {
				continue;
			}
			$alias_re = self::aliases_to_regex($src['aliases']);
			if ($alias_re === '') {
				continue;
			}
			// Контекст атрибуции — паттерны вокруг alias'а (с обеих сторон).
			$attribution_patterns = self::attribution_patterns($alias_re);
			foreach ($attribution_patterns as $pattern) {
				$replaced = preg_replace_callback(
					$pattern,
					static function ($m) use (&$src) {
						if (! empty($src['linked'])) {
							return $m[0];
						}
						$src['linked'] = true;
						$alias_text = $m['name'] ?? '';
						if ($alias_text === '') {
							return $m[0];
						}
						$link = '<a href="' . esc_url($src['url']) . '" rel="' . self::REL_ATTRS . '" target="_blank">' . esc_html($alias_text) . '</a>';
						return str_replace($alias_text, $link, $m[0]);
					},
					$text,
					1, // только первое совпадение
					$replaced_count
				);
				if (is_string($replaced) && $replaced_count > 0) {
					$text = $replaced;
					break; // переходим к следующему source — этот уже linked
				}
			}
		}
		unset($src);
		return $text;
	}

	/**
	 * Список регекс-паттернов, которые описывают атрибутные конструкции
	 * вокруг имени источника. Каждый паттерн содержит named group `name`
	 * = собственно имя источника.
	 */
	private static function attribution_patterns(string $alias_re): array {
		// `\b` границы добавлены вокруг alias чтобы не ловить «BBCode»
		// при alias=BBC.
		$NAME = '(?P<name>' . $alias_re . ')';
		return [
			// === German ===
			// Wie [die|der|das] X berichtet|meldet|mitteilt|schreibt
			'/(?<![\p{L}\p{N}])Wie\s+(?:die\s+|der\s+|das\s+)?' . $NAME . '\s+(?:berichtet|meldet|mitteilt|schreibt|erkl[äa]rt)(?![\p{L}\p{N}])/u',
			// [Die|Der|Das] X berichtet/meldet/mitteilt/schreibt — без Wie
			'/(?<![\p{L}\p{N}])(?:Die|Der|Das)\s+' . $NAME . '\s+(?:berichtet|meldet|mitteilt|schreibt|sagt|erkl[äa]rt|teilt\s+mit)(?![\p{L}\p{N}])/u',
			// X berichtet/meldet/schreibt — короткая форма без артикля
			'/(?<![\p{L}\p{N}])' . $NAME . '\s+(?:berichtet|meldet|mitteilt|schreibt|sagt|erkl[äa]rt|teilt\s+mit)(?![\p{L}\p{N}])/u',
			// V2-инверсия: «berichtet/meldet/schreibt X» (verb-first после
			// предложного зачина типа «Dazu berichtet X» или standalone).
			'/(?<![\p{L}\p{N}])(?:berichtet|meldet|mitteilt|schreibt|schrieb|sagt|sagte|erkl[äa]rt(?:e)?|teilt\s+mit|teilte\s+mit)\s+(?:die\s+|der\s+|das\s+|von\s+)?' . $NAME . '(?![\p{L}\p{N}])/u',
			// laut X / Laut X
			'/(?<![\p{L}\p{N}])[Ll]aut\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// nach Angaben von X
			'/(?<![\p{L}\p{N}])[Nn]ach\s+Angaben\s+(?:von|der|des)\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// nach Informationen von X
			'/(?<![\p{L}\p{N}])[Nn]ach\s+Informationen\s+(?:von|der|des)\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// X zufolge
			'/(?<![\p{L}\p{N}])' . $NAME . '\s+zufolge(?![\p{L}\p{N}])/u',
			// === Ukrainian / Russian ===
			// За повідомленням X / За повідомленнями X
			'/(?<![\p{L}\p{N}])[Зз]а\s+повідомленням(?:и)?\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// Як повідомляє X
			'/(?<![\p{L}\p{N}])[Яя]к\s+повідомля[єют][\p{Ll}]*\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// За даними X / За інформацією X / За словами X
			'/(?<![\p{L}\p{N}])[Зз]а\s+(?:даними|інформацією|словами)\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// Пише X / Інформує X — leading сапитал может быть
			'/(?<![\p{L}\p{N}])(?:[Пп]ише|[Іі]нформує|[Зз]азначає|[Зз]ауважує)\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// сообщает X / по сообщению X / по данным X
			'/(?<![\p{L}\p{N}])[Сс]ообщает\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			'/(?<![\p{L}\p{N}])[Пп]о\s+(?:сообщению|данным|информации)\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// «повідомляє X» в конце предложения
			'/(?<![\p{L}\p{N}])повідомля[єют][\p{Ll}]*\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// === English ===
			// according to [The] X
			'/(?<![\p{L}\p{N}])[Aa]ccording\s+to\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			// X reports / X said / X writes
			'/(?<![\p{L}\p{N}])' . $NAME . '\s+(?:reports?|said|says|writes|wrote)(?![\p{L}\p{N}])/u',
			// as reported by X / as X reports / as X reported
			'/(?<![\p{L}\p{N}])[Aa]s\s+reported\s+by\s+' . $NAME . '(?![\p{L}\p{N}])/u',
			'/(?<![\p{L}\p{N}])[Aa]s\s+' . $NAME . '\s+report(?:s|ed)?(?![\p{L}\p{N}])/u',
			// per X
			'/(?<![\p{L}\p{N}])[Pp]er\s+' . $NAME . '(?![\p{L}\p{N}])/u',
		];
	}

	/**
	 * Построить regex-альтернативу из массива aliases.
	 * Сортируем DESC по длине чтобы более длинные совпадения попадали первыми.
	 */
	private static function aliases_to_regex(array $aliases): string {
		$aliases = array_values(array_unique(array_filter(array_map('strval', $aliases))));
		if ($aliases === []) {
			return '';
		}
		usort($aliases, static fn(string $a, string $b): int => mb_strlen($b) - mb_strlen($a));
		$quoted = array_map(static fn(string $a): string => preg_quote($a, '/'), $aliases);
		return '(?:' . implode('|', $quoted) . ')';
	}

	/**
	 * Из {name, domain} построить список разумных текстовых вариантов как
	 * имя источника может встречаться в статье.
	 *
	 * Примеры:
	 *  name="ARD Tagesschau", domain="tagesschau.de"
	 *    → ["ARD Tagesschau", "Tagesschau", "tagesschau.de"]
	 *  name="The Guardian World", domain="theguardian.com"
	 *    → ["The Guardian World", "The Guardian", "Guardian", "theguardian.com"]
	 */
	private static function build_aliases(string $name, string $domain): array {
		$aliases = [];
		$name = trim($name);
		if ($name !== '') {
			$aliases[] = $name;
			// "Tagesschau direkt" → "Tagesschau"
			// "Reuters World (via GN)" → "Reuters World", "Reuters"
			$cleaned = preg_replace('/\s*\([^)]+\)\s*/', ' ', $name);
			$cleaned = trim((string) $cleaned);
			if ($cleaned !== '' && $cleaned !== $name) {
				$aliases[] = $cleaned;
			}
			// Возьмём первое слово как короткую форму, если name >= 2 слова и
			// первое слово >= 3 символов (избегаем «Die», «Der», «The»).
			$tokens = preg_split('/\s+/u', $cleaned !== '' ? $cleaned : $name) ?: [];
			$skip_first = ['Die', 'Der', 'Das', 'Den', 'The', 'A', 'An'];
			$first_idx = 0;
			while ($first_idx < count($tokens) && in_array($tokens[$first_idx] ?? '', $skip_first, true)) {
				$first_idx++;
			}
			if (isset($tokens[$first_idx]) && mb_strlen($tokens[$first_idx], 'UTF-8') >= 3) {
				$aliases[] = $tokens[$first_idx];
			}
			// «article + первое слово» — частая форма цитирования в DE/EN:
			// «The Guardian», «Die BBC», «Der Spiegel». Эта форма должна
			// быть aliased даже если на DB-уровне name записан с придатком
			// («The Guardian World», «Der SPIEGEL News»).
			if ($first_idx > 0 && isset($tokens[$first_idx])) {
				$with_article = trim(implode(' ', array_slice($tokens, 0, $first_idx + 1)));
				if ($with_article !== '') {
					$aliases[] = $with_article;
				}
			}
			// "Süddeutsche Zeitung" → "SZ"-стиль: только если уже не короткий
			// Для multi-word: первые 2 слова (для "Frankfurter Allgemeine Zeitung" → "Frankfurter Allgemeine")
			if (count($tokens) >= 3) {
				$two = trim(implode(' ', array_slice($tokens, $first_idx, 2)));
				if ($two !== '' && mb_strlen($two, 'UTF-8') >= 6) {
					$aliases[] = $two;
				}
			}
		}
		if ($domain !== '') {
			$aliases[] = $domain;
			// Без TLD: tagesschau.de → "Tagesschau"
			$without_tld = preg_replace('/\.[a-z]{2,8}(?:\.[a-z]{2,4})?$/i', '', $domain);
			if (is_string($without_tld) && $without_tld !== $domain && $without_tld !== '') {
				// Capitalize first letter: tagesschau → Tagesschau
				$cap = mb_strtoupper(mb_substr($without_tld, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($without_tld, 1, null, 'UTF-8');
				$aliases[] = $cap;
			}
		}
		// Уникализируем и убираем пустоты
		return array_values(array_unique(array_filter(array_map('trim', $aliases))));
	}

	/**
	 * Из домена («n-tv.de», «theguardian.com», «tagesschau.de») получить
	 * читаемое имя бренда. Это для supporting-URL'ов от web-search где
	 * source-name явно не известен.
	 */
	private static function derive_name_from_domain(string $domain): string {
		$domain = preg_replace('/^www\./i', '', strtolower(trim($domain)));
		if ($domain === '') {
			return '';
		}
		// Snip TLD: "tagesschau.de" → "tagesschau"; "theguardian.com" → "theguardian"
		$base = preg_replace('/\.[a-z]{2,8}(?:\.[a-z]{2,4})?$/', '', $domain);
		if (! is_string($base) || $base === '') {
			return '';
		}
		// Special-case часто встречающиеся сокращения brand-name.
		$known = [
			'theguardian' => 'The Guardian',
			'sueddeutsche' => 'Süddeutsche',
			'spiegel' => 'Spiegel',
			'zeit' => 'ZEIT',
			'faz' => 'FAZ',
			'tagesschau' => 'tagesschau',
			'tagesspiegel' => 'Tagesspiegel',
			'deutschlandfunk' => 'Deutschlandfunk',
			'handelsblatt' => 'Handelsblatt',
			'welt' => 'Welt',
			'stern' => 'stern',
			'focus' => 'Focus',
			'reuters' => 'Reuters',
			'bbc' => 'BBC',
			'cnn' => 'CNN',
			'politico' => 'Politico',
			'euractiv' => 'EURACTIV',
			'euronews' => 'Euronews',
			'kyivpost' => 'Kyiv Post',
			'kyivindependent' => 'Kyiv Independent',
			'pravda' => 'Pravda',
			'ukrinform' => 'Ukrinform',
			'ukrainska-pravda' => 'Українська правда',
			'liga' => 'LIGA.net',
			'unian' => 'UNIAN',
			'24tv' => '24 канал',
			'br' => 'BR24',
			'ndr' => 'NDR',
			'wdr' => 'WDR',
			'zdf' => 'ZDF',
			'ard' => 'ARD',
			'merkur' => 'Münchner Merkur',
			'tz' => 'tz München',
			'heise' => 'Heise',
			't3n' => 't3n',
			'golem' => 'Golem',
			'sportschau' => 'Sportschau',
			'kicker' => 'kicker',
			'n-tv' => 'n-tv',
		];
		if (isset($known[$base])) {
			return $known[$base];
		}
		// Capitalize first letter, оставить остальное как есть.
		return mb_strtoupper(mb_substr($base, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($base, 1, null, 'UTF-8');
	}


	/**
	 * Найти отображаемое имя источника по source_id или fallback по domain
	 * URL'а.
	 */
	private static function resolve_source_name(int $source_id, string $url): string {
		if ($source_id > 0) {
			global $wpdb;
			$name = (string) $wpdb->get_var($wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}epv2_sources WHERE id=%d",
				$source_id
			));
			if ($name !== '') {
				return $name;
			}
		}
		if ($url !== '') {
			$h = wp_parse_url($url, PHP_URL_HOST);
			if (is_string($h)) {
				$h = preg_replace('/^www\./i', '', $h);
				return (string) $h;
			}
		}
		return '';
	}
}
