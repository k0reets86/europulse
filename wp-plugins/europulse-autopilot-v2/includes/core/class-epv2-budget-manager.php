<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Budget_Manager {
	public static function analyze_item(array $item, ?object $source = null): array {
		$title = mb_strtolower(wp_strip_all_tags((string) ($item['title'] ?? '')));
		$excerpt = mb_strtolower(wp_strip_all_tags((string) ($item['excerpt'] ?? '')));
		$content = mb_strtolower(wp_strip_all_tags((string) ($item['content'] ?? '')));
		$url = (string) ($item['url'] ?? ($source->url ?? ''));
		$category = (string) ($item['category'] ?? ($source->category_bias ?? ''));
		$detected_category = EPV2_Categorizer::detect(
			(string) ($item['title'] ?? ''),
			(string) (($item['content'] ?? '') !== '' ? ($item['content'] ?? '') : ($item['excerpt'] ?? '')),
			$category
		);
		if ($detected_category !== '') {
			$category = $detected_category;
		}
		$risk = (string) ($source->risk_level ?? 'low');
		$priority = (int) ($source->priority ?? 5);
		$reasons = [];
		$score = 0;

		$freshness_block = self::stale_news_block((string) ($item['date'] ?? ''), $title . ' ' . $excerpt . ' ' . $content, $category);
		if ($freshness_block !== '') {
			return [
				'score' => 0,
				'tier' => 'D',
				'decision' => 'reject',
				'breaking_candidate' => false,
				'top_story_candidate' => false,
				'consensus_mentions' => 0,
				'strictness' => (string) EPV2_Settings::get('ai_selection_strictness', 'medium'),
				'budget_mode' => (string) EPV2_Settings::get('ai_budget_mode', 'normal'),
				'reasons' => [$freshness_block],
				'category' => $category,
				'risk' => $risk,
				'priority' => $priority,
			];
		}

		if (self::looks_like_hard_reject($title, $excerpt, $content, $category)) {
			return [
				'score' => 0,
				'tier' => 'D',
				'decision' => 'reject',
				'breaking_candidate' => false,
				'top_story_candidate' => false,
				'consensus_mentions' => 0,
				'strictness' => (string) EPV2_Settings::get('ai_selection_strictness', 'medium'),
				'budget_mode' => (string) EPV2_Settings::get('ai_budget_mode', 'normal'),
				'reasons' => ['заведомо нерелевантный, обзорный или редакционно слабый сигнал'],
				'category' => $category,
				'risk' => $risk,
				'priority' => $priority,
			];
		}

		$score += self::category_weight($category);
		$score += self::source_priority_weight($priority);
		$score += self::risk_weight($risk);

		if (self::looks_like_noise($title, $excerpt, $content, $url) && ! self::has_rescue_signal($title . ' ' . $excerpt . ' ' . $content, $category)) {
			return [
				'score' => 0,
				'tier' => 'D',
				'decision' => 'reject',
				'breaking_candidate' => false,
				'top_story_candidate' => false,
				'consensus_mentions' => 0,
				'strictness' => (string) EPV2_Settings::get('ai_selection_strictness', 'medium'),
				'budget_mode' => (string) EPV2_Settings::get('ai_budget_mode', 'normal'),
				'reasons' => ['служебный, системный или заведомо шумовой материал'],
				'category' => $category,
				'risk' => $risk,
				'priority' => $priority,
			];
		}

		if (self::looks_official($url)) {
			$score += 12;
			$reasons[] = 'официальный или институциональный источник';
		}

		$freshness = self::freshness_weight((string) ($item['date'] ?? ''));
		$score += $freshness;
		if ($freshness >= 8) {
			$reasons[] = 'очень свежий материал';
		}

		$urgency = self::urgency_weight($title . ' ' . $excerpt);
		$score += $urgency;
		if ($urgency >= 12) {
			$reasons[] = 'есть признаки срочности';
		}

		$practical = self::practical_value_weight($title . ' ' . $excerpt . ' ' . $content);
		$score += $practical;
		if ($practical >= 10) {
			$reasons[] = 'высокая практическая польза';
		}

		$public_impact = self::public_impact_weight($title . ' ' . $excerpt . ' ' . $content, $category);
		$score += $public_impact;
		if ($public_impact >= 10) {
			$reasons[] = 'есть заметные последствия для широкой аудитории';
		}

		$community_value = self::community_event_weight($title . ' ' . $excerpt . ' ' . $content, $category);
		$score += $community_value;
		if ($community_value >= 8) {
			$reasons[] = 'сильная практическая ценность для сообщества';
		}

		$editorial_interest = self::editorial_interest_weight($title . ' ' . $excerpt . ' ' . $content, $category);
		$score += $editorial_interest;
		if ($editorial_interest >= 6) {
			$reasons[] = 'редакционно сильный информирующий сюжет для своей рубрики';
		}

		$routine_bureaucracy = self::routine_bureaucracy_weight($title . ' ' . $excerpt . ' ' . $content, $url, $category);
		$score += $routine_bureaucracy;
		if ($routine_bureaucracy <= -10) {
			$reasons[] = 'рутинный бюрократический или протокольный инфоповод';
		}

		$trivial_local = self::trivial_local_weight($title . ' ' . $excerpt . ' ' . $content, $category);
		$score += $trivial_local;
		if ($trivial_local <= -8) {
			$reasons[] = 'слишком мелкий локальный повод';
		}

		$old_story = self::old_story_penalty($title . ' ' . $excerpt . ' ' . $content);
		$score += $old_story;
		if ($old_story < 0) {
			$reasons[] = 'похоже на старую или архивную тему без новой ценности';
		}

		$consensus = self::consensus_weight($item);
		$score += $consensus['weight'];
		if ($consensus['mentions'] >= 2) {
			$reasons[] = 'сюжет подтверждается несколькими источниками';
		}

		$breaking_signal = EPV2_Breaking_Engine::analyze($item, $source, (int) $consensus['mentions'], $urgency);
		$score += min(14, (int) floor(($breaking_signal['confidence'] ?? 0) / 3));
		if (! empty($breaking_signal['reasons'])) {
			$reasons = array_merge($reasons, (array) $breaking_signal['reasons']);
		}

		$media = ! empty($item['image']) || ! empty($item['video']) ? 2 : 0;
		$score += $media;

		$trend = EPV2_Trends::trend_signal((string) ($item['title'] ?? ''), (string) (($item['excerpt'] ?? '') . ' ' . ($item['content'] ?? '')));
		if (! empty($trend['match'])) {
			$score += (int) ($trend['weight'] ?? 0);
			$reasons = array_merge($reasons, (array) ($trend['reasons'] ?? []));
		}

		if (
			self::looks_official($url)
			&& $routine_bureaucracy <= -10
			&& $public_impact < 8
			&& $practical < 8
			&& $community_value < 8
		) {
			return [
				'score' => 0,
				'tier' => 'D',
				'decision' => 'reject',
				'breaking_candidate' => false,
				'top_story_candidate' => false,
				'consensus_mentions' => 0,
				'strictness' => (string) EPV2_Settings::get('ai_selection_strictness', 'medium'),
				'budget_mode' => (string) EPV2_Settings::get('ai_budget_mode', 'normal'),
				'reasons' => ['официальный, но рутинный и малозначимый бюрократический инфоповод'],
				'category' => $category,
				'risk' => $risk,
				'priority' => $priority,
			];
		}

		$score = max(0, min(100, $score));
		$tier = self::tier_for_score($score);
		$flags = self::flags_for_score($score, $urgency, $consensus['mentions'], $category, $risk, $url, $breaking_signal);
		$decision = self::decision_for_score($score, $tier);

		return [
			'score' => $score,
			'tier' => $tier,
			'decision' => $decision,
			'breaking_candidate' => $flags['breaking_candidate'],
			'breaking_watch' => $flags['breaking_watch'],
			'breaking_confidence' => (int) ($breaking_signal['confidence'] ?? 0),
			'top_story_candidate' => $flags['top_story_candidate'],
			'consensus_mentions' => $consensus['mentions'],
			'strictness' => (string) EPV2_Settings::get('ai_selection_strictness', 'medium'),
			'budget_mode' => (string) EPV2_Settings::get('ai_budget_mode', 'normal'),
			'reasons' => array_values(array_unique($reasons)),
			'category' => $category,
			'risk' => $risk,
			'priority' => $priority,
		];
	}

	public static function should_send_to_ai(array $analysis): array {
		$mode = (string) EPV2_Settings::get('ai_budget_mode', 'normal');
		$strictness = (string) EPV2_Settings::get('ai_selection_strictness', 'medium');
		$threshold = self::score_threshold($mode, $strictness);
		$score = (int) ($analysis['score'] ?? 0);
		$tier = (string) ($analysis['tier'] ?? 'D');
		$category = (string) ($analysis['category'] ?? '');
		$budget = self::budget_state();
		if (in_array($category, ['sport', 'kultur', 'community', 'wirtschaft', 'world'], true) && $score >= max(24, $threshold['ai'] - 8) && $tier !== 'D') {
			$threshold['ai'] = max(24, $threshold['ai'] - 8);
		}
		if ($category === 'leben-in-deutschland' && $score >= max(22, $threshold['ai'] - 10) && $tier !== 'D') {
			$threshold['ai'] = max(22, $threshold['ai'] - 10);
		}

		if ($score < $threshold['reject']) {
			return ['allow' => false, 'mode' => 'reject', 'reason' => 'низкий рейтинг материала'];
		}

		if ($budget['hard_stop']) {
			if (in_array($tier, ['A'], true)) {
				return ['allow' => true, 'mode' => 'ai_priority_only', 'reason' => 'лимит достигнут, но материал приоритетный'];
			}
			return ['allow' => false, 'mode' => 'review_without_ai', 'reason' => 'достигнут AI-бюджет дня'];
		}

		if ($score < $threshold['ai']) {
			return ['allow' => false, 'mode' => 'review_without_ai', 'reason' => 'материал ниже AI-порога, оставить на ручную проверку'];
		}

		if ($mode === 'critical' && ! in_array($tier, ['A'], true)) {
			return ['allow' => false, 'mode' => 'review_without_ai', 'reason' => 'критичный режим экономии: AI только для tier A'];
		}

		if ($mode === 'economy' && ! in_array($tier, ['A', 'B'], true)) {
			return ['allow' => false, 'mode' => 'review_without_ai', 'reason' => 'режим экономии: AI только для сильных материалов'];
		}

		return ['allow' => true, 'mode' => 'ai_full', 'reason' => 'материал прошёл score и budget gate'];
	}

	public static function should_keep_in_queue(array $analysis): array {
		$strictness = (string) EPV2_Settings::get('ai_selection_strictness', 'medium');
		$mode = (string) EPV2_Settings::get('ai_budget_mode', 'normal');
		$score = (int) ($analysis['score'] ?? 0);
		$tier = (string) ($analysis['tier'] ?? 'D');
		$category = (string) ($analysis['category'] ?? '');
		$threshold = self::queue_keep_threshold($mode, $strictness);
		if (in_array($category, ['sport', 'kultur', 'community', 'wirtschaft', 'world'], true)) {
			$threshold = max(22, $threshold - 8);
		}
		if ($category === 'leben-in-deutschland') {
			$threshold = max(20, $threshold - 10);
		}

		if ($tier === 'A') {
			return ['keep' => true, 'reason' => 'tier A всегда остаётся в очереди'];
		}
		if ($score < $threshold) {
			return ['keep' => false, 'reason' => 'слишком низкий рейтинг для очереди'];
		}

		return ['keep' => true, 'reason' => 'материал достаточно силён для очереди'];
	}

	public static function budget_state(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_stats';
		$date = current_time('Y-m-d');
		$row = $wpdb->get_row($wpdb->prepare("SELECT rewritten, ai_tokens FROM {$table} WHERE metric_date = %s", $date), ARRAY_A);
		$rewritten = (int) ($row['rewritten'] ?? 0);
		$tokens = (int) ($row['ai_tokens'] ?? 0);
		$request_limit = max(1, (int) EPV2_Settings::get('ai_daily_request_soft_limit', 18));
		$token_limit = max(1000, (int) EPV2_Settings::get('ai_daily_token_soft_limit', 180000));
		return [
			'rewritten_today' => $rewritten,
			'tokens_today' => $tokens,
			'request_limit' => $request_limit,
			'token_limit' => $token_limit,
			'hard_stop' => $rewritten >= $request_limit || $tokens >= $token_limit,
		];
	}

	private static function score_threshold(string $mode, string $strictness): array {
		$matrix = [
			'normal' => ['low' => ['ai' => 28, 'reject' => 10], 'medium' => ['ai' => 32, 'reject' => 14], 'high' => ['ai' => 40, 'reject' => 18]],
			'economy' => ['low' => ['ai' => 32, 'reject' => 14], 'medium' => ['ai' => 38, 'reject' => 18], 'high' => ['ai' => 46, 'reject' => 22]],
			'critical' => ['low' => ['ai' => 54, 'reject' => 22], 'medium' => ['ai' => 62, 'reject' => 28], 'high' => ['ai' => 70, 'reject' => 34]],
		];
		return $matrix[$mode][$strictness] ?? $matrix['normal']['medium'];
	}

	private static function queue_keep_threshold(string $mode, string $strictness): int {
		$matrix = [
			'normal' => ['low' => 22, 'medium' => 28, 'high' => 34],
			'economy' => ['low' => 28, 'medium' => 34, 'high' => 40],
			'critical' => ['low' => 48, 'medium' => 56, 'high' => 64],
		];
		return $matrix[$mode][$strictness] ?? 42;
	}

	private static function category_weight(string $category): int {
		$weights = [
			'politik' => 14,
			'wirtschaft' => 12,
			'deutschland' => 12,
			'leben-in-deutschland' => 13,
			'bayern' => 11,
			'münchen' => 11,
			'ukraine' => 12,
			'europa' => 10,
			'community' => 11,
			'world' => 11,
			'kultur' => 10,
			'sport' => 10,
		];
		return $weights[$category] ?? 6;
	}

	private static function source_priority_weight(int $priority): int {
		return max(0, min(10, $priority));
	}

	private static function risk_weight(string $risk): int {
		return match ($risk) {
			'safe' => 10,
			'low' => 7,
			'moderate' => 3,
			'high', 'critical' => -6,
			default => 0,
		};
	}

	private static function freshness_weight(string $date): int {
		if ($date === '') {
			return 2;
		}
		$timestamp = strtotime($date);
		if (! $timestamp) {
			return 2;
		}
		$age = time() - $timestamp;
		if ($age <= 2 * HOUR_IN_SECONDS) {
			return 10;
		}
		if ($age <= 8 * HOUR_IN_SECONDS) {
			return 8;
		}
		if ($age <= DAY_IN_SECONDS) {
			return 5;
		}
		return 1;
	}

	private static function stale_news_block(string $date, string $text, string $category = ''): string {
		if ($date === '') {
			return '';
		}
		$timestamp = strtotime($date);
		if (! $timestamp) {
			return '';
		}
		$age = time() - $timestamp;
		if ($age > (48 * HOUR_IN_SECONDS)) {
			if (self::allow_evergreen_stale_bypass($text, $category, $age)) {
				return '';
			}
			return 'устаревшая новость: исходный материал слишком старый для текущей публикации';
		}

		if (self::has_future_or_active_event_window($text)) {
			return '';
		}

		$currentYear = (int) gmdate('Y');
		if (preg_match_all('/\b(\d{1,2})\.\s*(januar|februar|m[äa]rz|april|mai|juni|juli|august|september|oktober|november|dezember)(?:\s+(\d{4}))?\b/ui', $text, $matches, PREG_SET_ORDER)) {
			$monthMap = [
				'januar' => 1,
				'februar' => 2,
				'märz' => 3,
				'marz' => 3,
				'april' => 4,
				'mai' => 5,
				'juni' => 6,
				'juli' => 7,
				'august' => 8,
				'september' => 9,
				'oktober' => 10,
				'november' => 11,
				'dezember' => 12,
			];
			foreach ($matches as $match) {
				$day = (int) ($match[1] ?? 0);
				$month = $monthMap[mb_strtolower((string) ($match[2] ?? ''))] ?? 0;
				$year = (int) ($match[3] ?? $currentYear);
				if ($day <= 0 || $month <= 0) {
					continue;
				}
				$eventTs = gmmktime(12, 0, 0, $month, $day, $year > 0 ? $year : $currentYear);
				if ($eventTs > 0 && (time() - $eventTs) > (36 * HOUR_IN_SECONDS) && preg_match('/\b(wird|soll|geplant|startet|beginnt|am)\b/ui', $text)) {
					return 'устаревшая новость: в тексте упоминается уже прошедшая дата как предстоящее событие';
				}
			}
		}

		return '';
	}

	private static function allow_evergreen_stale_bypass(string $text, string $category, int $age): bool {
		if (! in_array($category, ['leben-in-deutschland', 'community'], true)) {
			return false;
		}

		$serviceTerms = [
			'jobcenter', 'arbeitsagentur', 'bamf', 'integration', 'sprachkurs', 'deutschkurs',
			'aufenthalt', 'aufenthaltstitel', 'anerkennung', 'einbürgerung', 'wohngeld', 'kindergeld',
			'beratung', 'jobsuche', 'bewerbung', 'ukrainische initiative', 'ukrainische gemeinde',
			'netzwerk', 'community', 'diaspora', 'hilfe', 'support',
			'дозвіл на проживання', 'інтеграц', 'консультац', 'робота', 'біжен', 'громад', 'ініціатив',
		];
		$eventTerms = [
			'heute', 'morgen', 'gestern', 'live', 'premiere', 'festival', 'konzert', 'kino',
			'veranstaltung am', 'treffen am', 'film', 'митинг', 'мітинг', 'сьогодні', 'завтра', 'учора',
			'konferenz', 'opening', 'vernissage', 'filmtage',
		];

		$serviceHits = 0;
		foreach ($serviceTerms as $term) {
			if (str_contains($text, $term)) {
				$serviceHits++;
			}
		}

		if ($category === 'leben-in-deutschland') {
			$futureYear = (int) gmdate('Y') + 1;
			$hasFutureValidity = preg_match('/\b(2027|2028|verlängert|gültig bis|gueltig bis|bis ende|temporary protection|vorübergehender schutz|temporary residence|schutzstatus)\b/u', $text) === 1;
			if ($age <= (10 * DAY_IN_SECONDS) && $serviceHits >= 1) {
				return true;
			}
			if ($age <= (180 * DAY_IN_SECONDS) && $serviceHits >= 1 && $hasFutureValidity) {
				return true;
			}
			if (preg_match('/\b' . $futureYear . '\b/u', $text) === 1 && $serviceHits >= 1 && $age <= (365 * DAY_IN_SECONDS)) {
				return true;
			}
			return false;
		}

		if ($age > (14 * DAY_IN_SECONDS) || $serviceHits < 2) {
			return false;
		}

		foreach ($eventTerms as $term) {
			if (str_contains($text, $term)) {
				return false;
			}
		}

		return true;
	}

	private static function has_future_or_active_event_window(string $text): bool {
		$timestamps = [];
		if (preg_match_all('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u', $text, $numericMatches, PREG_SET_ORDER)) {
			foreach ($numericMatches as $match) {
				$day = (int) ($match[1] ?? 0);
				$month = (int) ($match[2] ?? 0);
				$year = (int) ($match[3] ?? 0);
				if ($day <= 0 || $month <= 0 || $year <= 0) {
					continue;
				}
				$ts = gmmktime(12, 0, 0, $month, $day, $year);
				if ($ts > 0) {
					$timestamps[] = $ts;
				}
			}
		}

		$monthMap = [
			'januar' => 1,
			'februar' => 2,
			'märz' => 3,
			'marz' => 3,
			'april' => 4,
			'mai' => 5,
			'juni' => 6,
			'juli' => 7,
			'august' => 8,
			'september' => 9,
			'oktober' => 10,
			'november' => 11,
			'dezember' => 12,
		];
		$currentYear = (int) gmdate('Y');
		if (preg_match_all('/\b(\d{1,2})\.\s*(januar|februar|m[äa]rz|april|mai|juni|juli|august|september|oktober|november|dezember)(?:\s+(\d{4}))?\b/ui', $text, $namedMatches, PREG_SET_ORDER)) {
			foreach ($namedMatches as $match) {
				$day = (int) ($match[1] ?? 0);
				$month = $monthMap[mb_strtolower((string) ($match[2] ?? ''))] ?? 0;
				$year = (int) ($match[3] ?? $currentYear);
				if ($day <= 0 || $month <= 0 || $year <= 0) {
					continue;
				}
				$ts = gmmktime(12, 0, 0, $month, $day, $year);
				if ($ts > 0) {
					$timestamps[] = $ts;
				}
			}
		}

		if ($timestamps === []) {
			return false;
		}
		rsort($timestamps);
		return (int) $timestamps[0] >= (time() - (36 * HOUR_IN_SECONDS));
	}

	private static function urgency_weight(string $text): int {
		$terms = [
			'breaking', 'eilmeldung', 'dringend', 'sofort', 'urgent', 'live', 'attack', 'warnung', 'warnung',
			'терміново', 'термінов', 'негайно', 'екстрено', 'срочно', 'удар', 'атака', 'обстріл',
			'kabinett', 'bundesrat', 'bundestag', 'regierung', 'eu-gipfel', 'санкції', 'sanktionen',
		];
		$weight = 0;
		foreach ($terms as $term) {
			if (str_contains($text, $term)) {
				$weight += 4;
			}
		}
		return min(18, $weight);
	}

	private static function practical_value_weight(string $text): int {
		$terms = [
			'jobcenter', 'bürgergeld', 'kindergeld', 'visa', 'aufenthalt', 'integration', 'kurs', 'schule', 'wohnung',
			'mvg', 'mvv', 's-bahn', 'deutsche bahn', 'db regio', 'bahn', 'zugverkehr', 'fahrplan', 'fahrplanänderung', 'baustelle', 'sperrung', 'umleitung', 'ersatzverkehr', 'störung', 'betriebslage', 'u-bahn', 'tram', 'buslinie',
			'документ', 'виплати', 'робота', 'страхування', 'житло', 'дитсадок', 'школа', 'медицина',
			'поїзд', 'транспорт', 'дойчебан', 'перекриття', 'ремонт дороги', 'зміни руху', 'затримка',
			'arbeitsagentur', 'bamf', 'job', 'rent', 'housing', 'benefits', 'migration', 'family',
		];
		$weight = 0;
		foreach ($terms as $term) {
			if (str_contains($text, $term)) {
				$weight += 3;
			}
		}
		foreach (['mvg', 'mvv', 's-bahn', 'deutsche bahn', 'db regio', 'fahrplanänderung', 'ersatzverkehr', 'sperrung', 'umleitung', 'zugverkehr'] as $term) {
			if (str_contains($text, $term)) {
				$weight += 2;
			}
		}
		return min(15, $weight);
	}

	private static function public_impact_weight(string $text, string $category): int {
		$terms = [
			'gesetz', 'reform', 'abstimmung', 'beschluss', 'wahl', 'sanktionen', 'inflation', 'kraftstoff', 'miete',
			'arbeitsmarkt', 'arbeitslosigkeit', 'wohnungsmarkt', 'streik', 'ausfall', 'kürzung', 'förderung', 'hilfe',
			'ukraine', 'iran', 'krieg', 'angriff', 'sicherheit', 'terror', 'grenze', 'migration',
			'закон', 'реформа', 'санкц', 'вибори', 'скорочення', 'виплати', 'ринок праці', 'житло', 'безпека',
			'election', 'vote', 'sanctions', 'budget', 'fuel', 'housing', 'jobs', 'strike', 'attack', 'security',
		];
		$weight = 0;
		foreach ($terms as $term) {
			if (str_contains($text, $term)) {
				$weight += 3;
			}
		}
		foreach (['mvg', 'mvv', 's-bahn', 'deutsche bahn', 'db regio', 'fahrplanänderung', 'ersatzverkehr', 'sperrung', 'umleitung', 'zugverkehr'] as $term) {
			if (str_contains($text, $term)) {
				$weight += 2;
			}
		}
		if (in_array($category, ['politik', 'wirtschaft', 'deutschland', 'ukraine', 'europa', 'world'], true) && $weight > 0) {
			$weight += 2;
		}
		if (in_array($category, ['münchen', 'bayern', 'leben-in-deutschland'], true) && $weight > 0) {
			$weight += 1;
		}
		return min(16, $weight);
	}

	private static function community_event_weight(string $text, string $category): int {
		if (! in_array($category, ['community', 'kultur', 'münchen', 'leben-in-deutschland'], true)) {
			return 0;
		}
		$positive = [
			'jobsuche', 'jobmesse', 'beratung', 'bewerbung', 'deutschkurs', 'sprachkurs', 'netzwerk', 'meetup',
			'freiwilligen', 'psycholog', 'kinder', 'familien', 'integration', 'veranstaltung', 'anmeldung',
			'ukrainische gemeinde', 'ukrainische initiative', 'begegnung', 'ehrenamt', 'solidarität', 'münchen',
			'працевлаштування', 'робота', 'консультац', 'зустріч', 'курс', 'нетворкінг', 'волонтер', 'діти',
			'українськ', 'громад', 'ініціатив', 'мюнх',
			'volunteer', 'networking', 'job fair', 'language course', 'registration', 'community event',
			'ukrainian community', 'diaspora', 'community meetup', 'munich',
		];
		$negative = [
			'wir unterstützen', 'donate', 'spenden', 'spendenaufruf', 'über uns', 'verein des monats', 'grusswort', 'rückblick',
			'pressearchiv', 'archiv', 'фотозвіт', 'підтримайте', 'about us', 'donation',
		];
		$weight = 0;
		foreach ($positive as $term) {
			if (str_contains($text, $term)) {
				$weight += 3;
			}
		}
		foreach (['beratun', 'kostenlos', 'anmeldung', 'bewerbung', 'karriere', 'jobcenter', 'integration', 'psycholog', 'sprachkurs'] as $term) {
			if (str_contains($text, $term)) {
				$weight += 2;
			}
		}
		foreach ($negative as $term) {
			if (str_contains($text, $term)) {
				$weight -= 4;
			}
		}
		return max(-10, min(14, $weight));
	}

	private static function editorial_interest_weight(string $text, string $category): int {
		$terms = match ($category) {
			'sport' => [
				'bundesliga', 'champions league', 'dfb', 'fc bayern', 'spiel', 'match', 'tor', 'trainer',
				'sieg', 'niederlage', 'unentschieden', 'verein', 'transfer', 'stadion', 'sportschau',
				'матч', 'гра', 'перемога', 'поразка', 'тренер', 'клуб',
			],
			'kultur' => [
				'konzert', 'festival', 'premiere', 'ausstellung', 'museum', 'theater', 'oper', 'kino',
				'vernissage', 'kultur', 'performance', 'literatur', 'preisverleihung',
				'концерт', 'фестиваль', 'виставка', 'театр', 'опера', 'літератур',
			],
			'community' => [
				'treffen', 'veranstaltung', 'netzwerk', 'community', 'dialog', 'initiative', 'projekt',
				'workshop', 'seminar', 'diskussion', 'panel', 'hilfe', 'beratung',
				'зустріч', 'подія', 'ініціатив', 'проєкт', 'дискус', 'спільнот', 'громад',
				'ukrainische gemeinde', 'ukrainian community', 'ehrenamt', 'begegnung',
			],
			'leben-in-deutschland' => [
				'aufenthaltstitel', 'aufenthaltsrecht', 'einbürgerung', 'anerkennung', 'jobcenter', 'bamf',
				'arbeitsagentur', 'wohngeld', 'kindergeld', 'deutschkurs', 'sprachkurs', 'integration',
				'germany4ukraine', 'make it in germany', 'schutzstatus', 'fiktionsbescheinigung',
				'дозвіл на проживання', 'статус', 'робота в німеччині', 'соцвиплат', 'інтеграц', 'біжен',
			],
			default => [],
		};
		if ($terms === []) {
			return 0;
		}
		$weight = 0;
		foreach ($terms as $term) {
			if (str_contains($text, $term)) {
				$weight += 3;
			}
		}
		return min(10, $weight);
	}

	private static function routine_bureaucracy_weight(string $text, string $url, string $category): int {
		$strong_negative = [
			'terminhinweise', 'zum tod von', 'kondoliert', 'empfängt', 'nimmt an', 'antrittsbesuch',
			'besuch in', 'gratuliert', 'fragestunde', 'regierungserklärung im bundestag', 'stellt sich fragen',
			'digitaler staat', 'kongress', 'workshop', 'fachtagung', 'symposium', 'messe', 'expo', 'getex',
			'trauert um', 'gedenkt', 'nachruf',
			'appointments', 'schedule', 'condolence', 'receives', 'visit', 'itinerary',
		];
		$soft_negative = [
			'pressedienst', 'pressemitteilung', 'mitteilung', 'weiterlesen', 'tagesordnung', 'hinweis',
			'official notice', 'press release',
		];
		$weight = 0;
		foreach ($strong_negative as $term) {
			if (str_contains($text, $term) || str_contains($url, $term)) {
				$weight -= 8;
			}
		}
		foreach ($soft_negative as $term) {
			if (str_contains($text, $term) || str_contains($url, $term)) {
				$weight -= 3;
			}
		}
		if (in_array($category, ['community', 'kultur'], true)) {
			$weight = max($weight, -6);
		}
		return max(-18, $weight);
	}

	private static function trivial_local_weight(string $text, string $category): int {
		if (! in_array($category, ['münchen', 'bayern', 'community', 'deutschland'], true)) {
			return 0;
		}
		$terms = [
			'baum', 'bäume', 'pflanz', 'grünfläche', 'blumenbeet', 'museum geöffnet', 'wettbewerb',
			'lieblingsbusfahrer', 'jubiläum', 'broschüre', 'auszeichnung', 'modellbahnausstellung',
			'mitmachen', 'gewinnspiel', 'lieblings', 'museum an zwei tagen geöffnet',
			'übersicht', 'baustellen-übersicht', 'fahrplanänderungen im mvv', 'faq', 'fragen und antworten',
			'дерев', 'висадять', 'квіти', 'ювілей', 'конкурс', 'відзнака',
		];
		$transport_terms = [
			'mvg', 'mvv', 's-bahn', 'deutsche bahn', 'fahrplan', 'fahrplanänderung', 'baustelle', 'ersatzverkehr',
			'störung', 'betriebslage', 'u-bahn', 'tram', 'bus', 'umleitung', 'sperrung', 'zugverkehr',
		];
		foreach ($transport_terms as $term) {
			if (str_contains($text, $term)) {
				return 0;
			}
		}
		$penalty = 0;
		foreach ($terms as $term) {
			if (str_contains($text, $term)) {
				$penalty -= 4;
			}
		}
		return max(-12, $penalty);
	}

	private static function consensus_weight(array $item): array {
		global $wpdb;
		$title = self::normalize_title((string) ($item['title'] ?? ''));
		if ($title === '') {
			return ['mentions' => 0, 'weight' => 0];
		}
		$like = '%' . $wpdb->esc_like(mb_substr($title, 0, 48)) . '%';
		$table = $wpdb->prefix . 'epv2_queue';
		$mentions = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT source_id) FROM {$table} WHERE original_title LIKE %s AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
			$like
		));
		$weight = match (true) {
			$mentions >= 5 => 16,
			$mentions >= 3 => 10,
			$mentions >= 2 => 6,
			default => 0,
		};
		return ['mentions' => $mentions, 'weight' => $weight];
	}

	private static function normalize_title(string $title): string {
		$title = mb_strtolower(trim(wp_strip_all_tags($title)));
		$title = preg_replace('/\s+/u', ' ', $title) ?: $title;
		return $title;
	}

	private static function tier_for_score(int $score): string {
		return match (true) {
			$score >= 70 => 'A',
			$score >= 52 => 'B',
			$score >= 34 => 'C',
			default => 'D',
		};
	}

	private static function flags_for_score(int $score, int $urgency, int $mentions, string $category, string $risk, string $url, array $breaking_signal = []): array {
		$breaking = ($score >= 72 && $urgency >= 10 && $mentions >= 2 && $risk !== 'high') || ! empty($breaking_signal['breaking']);
		$watch = ! empty($breaking_signal['watch']) || $breaking;
		$top = $score >= 64 && in_array($category, ['politik', 'wirtschaft', 'deutschland', 'ukraine', 'europa', 'world'], true);
		if (self::looks_official($url) && $urgency >= 8 && $mentions >= 2) {
			$breaking = true;
			$watch = true;
			$top = true;
		}
		return [
			'breaking_candidate' => $breaking,
			'breaking_watch' => $watch,
			'top_story_candidate' => $top,
		];
	}

	private static function looks_official(string $url): bool {
		$host = (string) parse_url($url, PHP_URL_HOST);
		$signals = ['bundesregierung', 'bundestag', 'europa.eu', 'eu-parliament', 'service.bund', 'bamf', 'arbeitsagentur', 'bmas', 'muenchen.de', 'bayern.de', 'ukrinform'];
		foreach ($signals as $signal) {
			if (str_contains($host, $signal) || str_contains($url, $signal)) {
				return true;
			}
		}
		return false;
	}

	private static function decision_for_score(int $score, string $tier): string {
		return match ($tier) {
			'A' => 'priority',
			'B' => 'strong',
			'C' => $score >= 40 ? 'review' : 'low',
			default => 'reject',
		};
	}

	private static function looks_like_noise(string $title, string $excerpt, string $content, string $url): bool {
		$text = trim($title . ' ' . $excerpt . ' ' . mb_substr($content, 0, 400));
		if ($text === '' || mb_strlen($text) < 24) {
			return true;
		}
		$noise_terms = [
			'rss', 'feed', 'newsletter', 'abo', 'impressum', 'privacy', 'cookie', 'datenschutz',
			'kontakt', 'login', 'registrierung', 'subscribe', 'opml', 'presse-archiv', 'veranstaltungskalender',
			'wir unterstützen', 'spenden', 'donate', 'read more', 'zurück zu startseite', 'startseite',
			'vorlesen', 'zur live map', 'tv und stream', 'watch sports online', 'lade bild',
			'baustellen-übersicht', 'fahrplanänderungen im mvv', 'faq', 'fragen und antworten',
			'digitaler staat', 'kongress', 'fachtagung', 'symposium', 'top-thema - der sacharow-preis 2023',
			'ursprung der sonne', 'astronomen entdecken', 'zum tod von', 'trauert um',
			'mehr erfahren mehr erfahren', 'mehr erfahren über gründe für störungen',
			'selbstverortung', 'zugehörigkeit', 'pressearchiv', 'pressearchiv',
			'stellenangebot', 'jobs', 'jobbörse', 'teilzeit', 'vollzeit', 'befristet',
			'arbeitsort', 'wochenarbeitszeit', 'vergütungsgruppe', 'entgeltgruppe',
			'diplom- oder masterabschluss', 'psychologischen psychotherapeuten',
			'fachassistent', 'service.bund.de',
		];
		foreach ($noise_terms as $term) {
			if (str_contains($text, $term) || str_contains($url, $term)) {
				return true;
			}
		}
		return false;
	}

	private static function has_rescue_signal(string $text, string $category): bool {
		$signals = [
			'jobsuche', 'bewerbung', 'beratung', 'sprachkurs', 'deutschkurs', 'jobcenter', 'integration',
			'psycholog', 'netzwerk', 'community event', 'freiwilligen', 'kostenlos', 'anmeldung',
			'mvg', 'mvv', 's-bahn', 'deutsche bahn', 'fahrplan', 'ersatzverkehr', 'sperrung', 'umleitung',
			'bürgergeld', 'bamf', 'arbeitsagentur', 'arbeitsmarkt', 'housing', 'migration',
			'bundesliga', 'fc bayern', 'match', 'spiel', 'trainer', 'transfer', 'stadion',
			'konzert', 'festival', 'premiere', 'ausstellung', 'museum', 'theater', 'oper', 'kino',
			'community', 'initiative', 'projekt', 'diskussion', 'veranstaltung', 'treffen',
		];
		foreach ($signals as $signal) {
			if (str_contains($text, $signal)) {
				return true;
			}
		}
		if (in_array($category, ['leben-in-deutschland', 'community'], true) && preg_match('/\b(job|arbeit|kurs|beratung|treffen|event|hilfe)\b/u', $text) === 1) {
			return true;
		}
		if ($category === 'sport' && preg_match('/\b(spiel|match|bundesliga|trainer|transfer|verein|stadion)\b/u', $text) === 1) {
			return true;
		}
		if ($category === 'kultur' && preg_match('/\b(konzert|festival|premiere|ausstellung|museum|theater|oper|kino)\b/u', $text) === 1) {
			return true;
		}
		return false;
	}

	private static function looks_like_hard_reject(string $title, string $excerpt, string $content, string $category): bool {
		$headline = trim($title . ' ' . $excerpt . ' ' . mb_substr($content, 0, 300));
		$normalized_title = trim(mb_strtolower(wp_strip_all_tags($title)));
		if ($normalized_title === '' || in_array($normalized_title, ['news', 'update', 'ticker', 'live', 'meldung'], true)) {
			return true;
		}
		if (in_array($normalized_title, ['tagesschau', 'bild', 'reuters', 'dpa'], true) && ($excerpt === '' || $excerpt === $normalized_title)) {
			return true;
		}
		$patterns = [
			'/^bv-empfehlung\b/u',
			'/\bprobealarm\b/u',
			'/\btagesschau in 100 sekunden\b/u',
			'/\bin 100 sekunden\b/u',
			'/\bps plus\b|\bplaystation plus\b|\bxbox game pass\b|\bnintendo direct\b/u',
			'/\bspace marine\b|\bpersona 5\b|\bbonus-spiele\b|\bgratis-spiele\b/u',
			'/\bkonsole\b|\bvideospiel\b|\bgaming\b|\bps5\b|\bps4\b|\bxbox\b|\bnintendo switch\b/u',
			'/mehr erfahren.*störungen im s-bahn-verkehr/u',
			'/ursprung der sonne|astronomen entdecken/u',
			'/digitaler staat|workshop on land policies/u',
			'/selbstverortung|zugehörigkeit/u',
			'/top-thema.*sacharow-preis 2023/u',
			'/nimmt an kabinettsitzung teil/u',
			'/nimmt an .*sitzung teil/u',
			'/antrittsbesuch|arbeitsbesuch|tritt .* teil/u',
			'/minister .* nimmt an/u',
			'/empfängt|gratuliert|kondoliert|trauert um|zum tod von/u',
			'/terminhinweise|pressekalender|tagesordnung/u',
			'/\bpressearchiv\b|\bpressearchiv\b/u',
			'/\bstellenangebot\b|\bjobbörse\b|\bjob posting\b/u',
			'/\b(m\/w\/d|w\/m\/d|w\/md|m\/f\/d)\b/u',
			'/\bteilzeit\b|\bvollzeit\b|\bbefristet\b|\barbeitsort\b/u',
			'/\bfachassistent\b|\bpsycholog(?:en|ischen)?\b/u',
			'/\bdiplom- oder masterabschluss\b|\bpsychologischen psychotherapeuten\b/u',
			'/\bteilprojektverantwortung\b|\barbeitgeber:\b|\bveröffentlichungsende:\b/u',
			'/\bsport\s*-\s*[a-z0-9.-]+$/u',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $headline)) {
				return true;
			}
		}
		if ($category === 'münchen' && preg_match('/übersicht|mehr erfahren/u', $headline)) {
			return true;
		}
		return false;
	}

	private static function old_story_penalty(string $text): int {
		$currentYear = (int) gmdate('Y');
		if (preg_match_all('/\b(20\d{2})\b/u', $text, $matches)) {
			foreach ((array) ($matches[1] ?? []) as $year) {
				$year = (int) $year;
				if ($year > 2000 && $year < ($currentYear - 1)) {
					return -12;
				}
			}
		}
		return 0;
	}
}
