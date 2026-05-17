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
			$category = self::canonical_category((string) ($item['category'] ?? ($source->category_bias ?? '')));
		$detected_category = EPV2_Categorizer::detect(
			(string) ($item['title'] ?? ''),
			(string) (($item['content'] ?? '') !== '' ? ($item['content'] ?? '') : ($item['excerpt'] ?? '')),
			$category
		);
			if ($detected_category !== '') {
				$category = self::canonical_category($detected_category);
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
				'reject_class' => 'stale',
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

		if (self::looks_like_hard_reject($title, $excerpt, $content, $category, $url)) {
			return [
				'score' => 0,
				'tier' => 'D',
				'decision' => 'reject',
				'reject_class' => 'hard_pattern',
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

		$sportFixtureBlock = self::sport_live_fixture_block($title, $excerpt, $content, $category);
		if ($sportFixtureBlock !== '') {
			return [
				'score' => 0,
				'tier' => 'D',
				'decision' => 'reject',
				'reject_class' => 'sport_fixture_livepage',
				'breaking_candidate' => false,
				'top_story_candidate' => false,
				'consensus_mentions' => 0,
				'strictness' => (string) EPV2_Settings::get('ai_selection_strictness', 'medium'),
				'budget_mode' => (string) EPV2_Settings::get('ai_budget_mode', 'normal'),
				'reasons' => [$sportFixtureBlock],
				'category' => $category,
				'risk' => $risk,
				'priority' => $priority,
			];
		}

		$telegramPromo = self::telegram_community_promo_block(
			(string) ($item['title'] ?? ''),
			(string) ($item['excerpt'] ?? ''),
			(string) ($item['content'] ?? ''),
			$url,
			$category
		);
		if ($telegramPromo !== '') {
			return [
				'score' => 0,
				'tier' => 'D',
				'decision' => 'reject',
				'reject_class' => 'community_promo',
				'breaking_candidate' => false,
				'top_story_candidate' => false,
				'consensus_mentions' => 0,
				'strictness' => (string) EPV2_Settings::get('ai_selection_strictness', 'medium'),
				'budget_mode' => (string) EPV2_Settings::get('ai_budget_mode', 'normal'),
				'reasons' => [$telegramPromo],
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
				'reject_class' => 'noise',
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

		$soft_low_value = self::soft_low_public_value_penalty($title . ' ' . $excerpt . ' ' . $content, $category, $practical, $public_impact, $community_value, $urgency, $editorial_interest);
		$score += $soft_low_value;
		if ($soft_low_value <= -8) {
			$reasons[] = 'мягкий animal/oddity сюжет без достаточной общественной или практической ценности';
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
				'reject_class' => 'routine_official',
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

		$score = self::uplift_borderline_newsworthy_score(
			$score,
			$category,
			$title . ' ' . $excerpt . ' ' . $content,
			$url,
			$priority,
			$urgency,
			$practical,
			$public_impact,
			$editorial_interest,
			(int) ($consensus['mentions'] ?? 0)
		);
			$score = max(0, min(100, $score));
			$preliminary_tier = self::tier_for_score($score, $category);
			$mix_adjustment = EPV2_Category_Planner::selection_adjustment($category, [
				'decision' => self::decision_for_score($score, $preliminary_tier, $category),
				'breaking_candidate' => ! empty($breaking_signal['candidate']) || ! empty($breaking_signal['breaking_candidate']),
				'top_story_candidate' => ! empty($breaking_signal['top_story']) || ! empty($breaking_signal['top_story_candidate']),
			]);
			$dynamic_threshold_delta = (int) ($mix_adjustment['delta'] ?? 0);
			if ($dynamic_threshold_delta !== 0) {
				$reasons[] = $dynamic_threshold_delta < 0
					? 'рубрика недопредставлена: порог прохода мягко снижен без искусственного повышения score'
					: 'рубрика перепредставлена: порог прохода мягко повышен без обнуления редакционной оценки';
			}
			$tier = self::tier_for_score($score, $category);
			$flags = self::flags_for_score($score, $urgency, $consensus['mentions'], $category, $risk, $url, $breaking_signal);
			$decision = self::decision_for_score($score, $tier, $category, $mix_adjustment);
			$scorecard = self::category_scorecard($category);

			return [
				'score' => $score,
				'tier' => $tier,
				'decision' => $decision,
			'reject_class' => $decision === 'reject' ? 'low_score' : '',
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
				'scorecard' => [
					'a' => (int) $scorecard['a'],
					'b' => (int) $scorecard['b'],
					'c' => (int) $scorecard['c'],
					'publish_c' => (int) $scorecard['publish_c'],
					'dimensions' => (array) $scorecard['dimensions'],
				],
				'dynamic_threshold' => [
					'delta' => $dynamic_threshold_delta,
					'reason' => (string) ($mix_adjustment['reason'] ?? ''),
					'stats' => (array) ($mix_adjustment['stats'] ?? []),
				],
			];
		}

	public static function analyze_contextual(array $item, array $dossier = [], array $seed = []): array {
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		$event = is_array($dossier['event_context'] ?? null) ? $dossier['event_context'] : [];
		$story = is_array($dossier['story_context'] ?? null) ? $dossier['story_context'] : [];
		$title = (string) ($primary['title'] ?? ($item['title'] ?? ''));
		$content = (string) ($primary['content'] ?? ($item['content'] ?? ''));
		$excerpt = (string) ($primary['excerpt'] ?? ($item['excerpt'] ?? ''));
		$body_focus = trim(implode("\n", array_filter([
			$content,
			(string) ($story['body_snippet'] ?? ''),
			implode('. ', array_map('strval', (array) ($event['fact_snippets'] ?? []))),
		])));
		$body_detect = $body_focus !== '' ? $body_focus : ($content !== '' ? $content : $excerpt);
			$seed_category = self::canonical_category((string) ($seed['category'] ?? ($item['category'] ?? '')));
		$detected_category = EPV2_Categorizer::detect('', $body_detect, $seed_category);
		$refined_category = EPV2_Categorizer::refine_with_event_context(
			$detected_category !== '' ? $detected_category : $seed_category,
			$dossier,
			$title,
			$body_detect
		);
		$analysis = self::analyze_item([
			'title' => '',
			'excerpt' => $excerpt,
			'content' => $body_detect,
			'url' => (string) ($primary['url'] ?? ($item['url'] ?? '')),
			'date' => (string) ($item['date'] ?? ''),
			'image' => (string) ($item['image'] ?? ''),
				'category' => $refined_category !== '' ? self::canonical_category($refined_category) : $seed_category,
			]);

		$analysis['mode'] = 'contextual';
		$analysis['body_detected_category'] = $detected_category;
			$analysis['category'] = self::canonical_category($refined_category !== '' ? $refined_category : (string) ($analysis['category'] ?? $seed_category));
		$analysis['supporting_count'] = count((array) ($dossier['supporting'] ?? []));
		$analysis['event_kind'] = sanitize_key((string) ($event['kind'] ?? ''));
		$analysis['search_terms'] = array_values(array_slice(array_unique(array_filter(array_map('sanitize_text_field', (array) ($story['search_terms'] ?? [])))), 0, 6));
		if (
			(int) $analysis['supporting_count'] === 0
			&& self::looks_like_non_news_service_page($title, $excerpt, $body_detect, (string) ($primary['url'] ?? ($item['url'] ?? '')))
			&& ! self::has_future_or_active_event_window(mb_strtolower($title . ' ' . $excerpt . ' ' . $body_detect))
		) {
			$analysis['score'] = 0;
			$analysis['tier'] = 'D';
			$analysis['decision'] = 'reject';
			$analysis['reject_class'] = 'noise';
			$analysis['reasons'][] = 'generic service or landing page without supporting context';
		}

		if ($analysis['supporting_count'] >= 2) {
			$analysis['score'] = min(100, (int) ($analysis['score'] ?? 0) + 6);
			$analysis['reasons'][] = 'контекст подтверждён дополнительными источниками';
		}
		if ($body_focus !== '' && mb_strlen(wp_strip_all_tags($body_focus)) >= 400) {
			$analysis['score'] = min(100, (int) ($analysis['score'] ?? 0) + 4);
			$analysis['reasons'][] = 'контекстная оценка опирается на тело материала';
		}
		if (in_array((string) $analysis['category'], ['politik', 'sport', 'wirtschaft', 'leben-in-deutschland', 'community'], true)) {
			$analysis['score'] = min(100, (int) ($analysis['score'] ?? 0) + 2);
		}

		$is_official = self::looks_official((string) ($primary['url'] ?? ($item['url'] ?? '')));
		$body_len = mb_strlen(wp_strip_all_tags($body_detect));
		$has_service_signal = in_array((string) $analysis['category'], ['leben-in-deutschland', 'community'], true)
			&& preg_match('/\b(integrationskurs|orientierungskurs|bamf|aufenthalt|sprachkurs|beratung|jobcenter|arbeitsagentur|kindergeld|wohngeld)\b/ui', $body_detect) === 1;
		$looks_protocol = preg_match('/\b(kondolenz|kondolenztelegramm|trauert um|zum tod von|gratuliert|empfängt|antrittsbesuch|arbeitsbesuch|nimmt an .* teil|regierungserklärung)\b/ui', $title . ' ' . $excerpt . ' ' . $body_detect) === 1;
		if (
			(string) ($analysis['decision'] ?? '') === 'reject'
			&& in_array((string) ($analysis['reject_class'] ?? ''), ['', 'low_score', 'hard_pattern'], true)
			&& ! $looks_protocol
			&& (
				($is_official && $body_len >= 180)
				|| $has_service_signal
				|| (! empty($analysis['supporting_count']) && (int) $analysis['supporting_count'] >= 1)
			)
		) {
			$analysis['score'] = max(24, (int) ($analysis['score'] ?? 0));
			$analysis['reject_class'] = '';
			$analysis['reasons'][] = 'контекст достаточно содержательный для автоматического усиления, а не для мгновенного отклонения';
		}

			$analysis['tier'] = self::tier_for_score((int) ($analysis['score'] ?? 0), (string) ($analysis['category'] ?? ''));
			$analysis['decision'] = self::decision_for_score((int) ($analysis['score'] ?? 0), (string) ($analysis['tier'] ?? 'D'), (string) ($analysis['category'] ?? ''));
			$analysis['reasons'] = array_values(array_unique(array_filter(array_map('strval', (array) ($analysis['reasons'] ?? [])))));

		return $analysis;
	}

	public static function should_send_to_ai(array $analysis): array {
		$mode = (string) EPV2_Settings::get('ai_budget_mode', 'normal');
		$strictness = (string) EPV2_Settings::get('ai_selection_strictness', 'medium');
		$threshold = self::score_threshold($mode, $strictness);
		$score = (int) ($analysis['score'] ?? 0);
		$tier = (string) ($analysis['tier'] ?? 'D');
			$category = self::canonical_category((string) ($analysis['category'] ?? ''));
			$budget = self::budget_state();
			$threshold = self::apply_category_ai_threshold($threshold, $category, $tier);

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
			$category = self::canonical_category((string) ($analysis['category'] ?? ''));
			$threshold = self::queue_keep_threshold($mode, $strictness);
			$threshold = self::apply_category_queue_threshold($threshold, $category);

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

	private static function canonical_category(string $category): string {
		$category = sanitize_text_field($category);
		if (class_exists('EPV2_Taxonomy_Map') && method_exists('EPV2_Taxonomy_Map', 'normalize_slug')) {
			return EPV2_Taxonomy_Map::normalize_slug($category);
		}
		return match (sanitize_title($category)) {
			'world' => 'welt',
			'münchen', 'munchen', 'munich' => 'muenchen',
			default => sanitize_title($category),
		};
	}

	private static function category_scorecard(string $category): array {
		$category = self::canonical_category($category);
		$base = [
			'a' => 70,
			'b' => 52,
			'c' => 34,
			'publish_c' => 40,
			'ai_delta' => 0,
			'queue_delta' => 0,
			'dimensions' => ['importance', 'informativeness', 'freshness', 'source_confidence'],
		];
		$cards = [
			// 2026-05-17 evening: publish_c 40 → 42 gentle tightening.
			// Avg published score 49.2 = большой запас. Items 40-41 уходят
			// в C-low. Ukraine оставлен 40 (war news имеет ценность даже
			// при low scores).
			'politik' => ['a' => 70, 'b' => 52, 'c' => 34, 'publish_c' => 42, 'dimensions' => ['public_impact', 'source_confidence', 'timeliness', 'informativeness']],
			// publish_c raised 40→45: Welt = world news, only the genuinely
			// resonant stories belong here. Niche-American / military-incident
			// items now slip from C-review into C-low and never make it past
			// Story-Card without being top_story_candidate.
			'welt' => ['a' => 70, 'b' => 52, 'c' => 34, 'publish_c' => 45, 'dimensions' => ['public_impact', 'international_relevance', 'source_confidence', 'timeliness']],
			'ukraine' => ['a' => 70, 'b' => 52, 'c' => 34, 'publish_c' => 40, 'dimensions' => ['war_relevance', 'human_impact', 'source_confidence', 'timeliness']],
			'europa' => ['a' => 68, 'b' => 50, 'c' => 34, 'publish_c' => 40, 'dimensions' => ['public_impact', 'policy_relevance', 'source_confidence']],
			// 2026-05-17 evening: publish_c 40 → 42. Avg published score 39.67
			// (с хвостом до 36 в min) — поднимаем порог чтобы зарезать
			// слабые. Сегодня 3/3 deutschland publishes были borderline.
			'deutschland' => ['a' => 68, 'b' => 50, 'c' => 34, 'publish_c' => 42, 'dimensions' => ['public_impact', 'reader_relevance', 'informativeness']],
			// publish_c history:
			//   ff4309d 2026-05-09 morning: raised 40→44 (operator quality bar)
			//   2026-05-09 evening:        reverted 44→40 (throughput drop)
			//   2026-05-17:                restored 40→44 (Q-fix #3 fixed
			//                              throughput root cause via
			//                              inline_stage_attempt_cap 2→4;
			//                              operator quality complaint: items
			//                              40-44 "не дотягивают по качеству")
			// Note: ai_delta/queue_delta removed (were -2/-2 in legacy revert
			// comment but already absent from current row). Effective
			// threshold == publish_c == 44.
			'wirtschaft' => ['a' => 68, 'b' => 50, 'c' => 34, 'publish_c' => 44, 'dimensions' => ['economic_impact', 'reader_relevance', 'informativeness']],
			'leben-in-deutschland' => ['a' => 66, 'b' => 48, 'c' => 32, 'publish_c' => 38, 'ai_delta' => -6, 'queue_delta' => -6, 'dimensions' => ['practical_value', 'reader_relevance', 'source_confidence', 'service_life']],
			'community' => ['a' => 66, 'b' => 48, 'c' => 32, 'publish_c' => 38, 'ai_delta' => -6, 'queue_delta' => -6, 'dimensions' => ['community_value', 'reader_relevance', 'practical_value', 'local_fit']],
			'muenchen' => ['a' => 66, 'b' => 48, 'c' => 32, 'publish_c' => 38, 'ai_delta' => -4, 'queue_delta' => -4, 'dimensions' => ['local_relevance', 'reader_relevance', 'informativeness', 'freshness']],
			'bayern' => ['a' => 66, 'b' => 48, 'c' => 32, 'publish_c' => 38, 'ai_delta' => -4, 'queue_delta' => -4, 'dimensions' => ['regional_relevance', 'reader_relevance', 'informativeness', 'freshness']],
			// kultur ослабили ai_delta -4 → -2, queue_delta -4 → -2 (2026-05-09):
			// 0 published kultur за 24h, 12 rejected. Эффективный threshold
			// был ~48, теперь ~44 — пропускаем больше науки/искусства/медиа.
			'kultur' => ['a' => 66, 'b' => 48, 'c' => 34, 'publish_c' => 40, 'ai_delta' => -2, 'queue_delta' => -2, 'dimensions' => ['editorial_interest', 'cultural_relevance', 'informativeness', 'freshness']],
			'sport' => ['a' => 66, 'b' => 50, 'c' => 34, 'publish_c' => 41, 'ai_delta' => -3, 'queue_delta' => -3, 'dimensions' => ['editorial_interest', 'event_relevance', 'diversity', 'freshness']],
		];
		return array_merge($base, is_array($cards[$category] ?? null) ? $cards[$category] : []);
	}

	private static function apply_category_ai_threshold(array $threshold, string $category, string $tier): array {
		if ($tier === 'D') {
			return $threshold;
		}
		$card = self::category_scorecard($category);
		$threshold['ai'] = max(20, (int) ($threshold['ai'] ?? 32) + (int) ($card['ai_delta'] ?? 0));
		$threshold['reject'] = max(8, (int) ($threshold['reject'] ?? 14) + min(0, (int) ($card['queue_delta'] ?? 0)));
		return $threshold;
	}

	private static function apply_category_queue_threshold(int $threshold, string $category): int {
		$card = self::category_scorecard($category);
		return max(18, $threshold + (int) ($card['queue_delta'] ?? 0));
	}

	private static function category_weight(string $category): int {
		$category = self::canonical_category($category);
		$weights = [
			'politik' => 14,
			'wirtschaft' => 12,
			'deutschland' => 12,
			'leben-in-deutschland' => 13,
			'bayern' => 11,
			'muenchen' => 11,
			'ukraine' => 12,
			'europa' => 10,
			'community' => 11,
			'welt' => 11,
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
		if (preg_match('/\bsommerzeit\b|\bwinterzeit\b|\bzeitumstellung\b|\buhr(?:en)?\s+(vor|zur[üu]ck|umstellen)\b|перев[ео]д.*час|літн[ійого].*час|зимов[ийого].*час/u', $text) === 1 && $age > (6 * HOUR_IN_SECONDS)) {
			return 'устаревшая time-sensitive новость: сюжет про перевод времени уже потерял актуальность';
		}
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
		$serviceTerms = [
			'jobcenter', 'arbeitsagentur', 'bamf', 'integration', 'sprachkurs', 'deutschkurs',
			'aufenthalt', 'aufenthaltstitel', 'anerkennung', 'einbürgerung', 'wohngeld', 'kindergeld',
			'beratung', 'jobsuche', 'bewerbung', 'ukrainische initiative', 'ukrainische gemeinde',
			'netzwerk', 'community', 'diaspora', 'hilfe', 'support',
			'krankenkasse', 'krankenkassen', 'beitragsrückerstattung', 'beitragsrueckerstattung',
			'wahltarif', 'wahltarife', 'selbstbehalt', 'familienversicherung', 'pflegeversicherung',
			'bonusprogramm', 'bonus programme', 'gesundheitsbonus', 'gesetzlich versichert',
			'health insurance', 'public insurance', 'refund', 'rebate',
			'дозвіл на проживання', 'інтеграц', 'консультац', 'робота', 'біжен', 'громад', 'ініціатив',
			'страхов', 'лікарнян', 'медичн', 'внеск', 'повернен',
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

		$categorySuggestsService = in_array($category, ['leben-in-deutschland', 'community'], true);
		if (! $categorySuggestsService && $serviceHits < 2) {
			return false;
		}

		$hasEvergreenGuideSignal = preg_match('/\b(so holen sie|so funktioniert|wer .* kann|bis zu \d+ euro|anspruch|voraussetzungen|bedingungen|antrag|beantragen|gilt|gilt weiterhin|weiterhin möglich)\b/ui', $text) === 1;

		if ($category === 'leben-in-deutschland' || (! $categorySuggestsService && $serviceHits >= 2)) {
			$futureYear = (int) gmdate('Y') + 1;
			$hasFutureValidity = preg_match('/\b(2027|2028|verlängert|gültig bis|gueltig bis|bis ende|temporary protection|vorübergehender schutz|temporary residence|schutzstatus)\b/u', $text) === 1;
			if ($age <= (10 * DAY_IN_SECONDS) && $serviceHits >= 1) {
				return true;
			}
			if ($age <= (180 * DAY_IN_SECONDS) && $serviceHits >= 1 && $hasFutureValidity) {
				return true;
			}
			if ($age <= (365 * DAY_IN_SECONDS) && $serviceHits >= 1 && $hasEvergreenGuideSignal) {
				return true;
			}
			if (preg_match('/\b' . $futureYear . '\b/u', $text) === 1 && $serviceHits >= 1 && $age <= (365 * DAY_IN_SECONDS)) {
				return true;
			}
			return false;
		}

		if ($age <= (180 * DAY_IN_SECONDS) && $serviceHits >= 2 && $hasEvergreenGuideSignal) {
			return true;
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
		$category = self::canonical_category($category);
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
		if (in_array($category, ['politik', 'wirtschaft', 'deutschland', 'ukraine', 'europa', 'welt'], true) && $weight > 0) {
			$weight += 2;
		}
		if (in_array($category, ['muenchen', 'bayern', 'leben-in-deutschland'], true) && $weight > 0) {
			$weight += 1;
		}
		return min(16, $weight);
	}

	private static function community_event_weight(string $text, string $category): int {
		$category = self::canonical_category($category);
		if (! in_array($category, ['community', 'kultur', 'muenchen', 'leben-in-deutschland'], true)) {
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
		$category = self::canonical_category($category);
		$terms = match ($category) {
			'politik' => [
				'bundestag', 'bundesrat', 'kanzler', 'regierung', 'minister', 'wahl', 'koalition',
				'gesetz', 'reform', 'haushalt', 'migration', 'parlament', 'abstimmung',
				// English equivalents — many German politics stories arrive
				// from English-language sources (DW EN, Google News Politics EN)
				// where the German term never appears.
				'chancellor', 'parliament', 'government', 'coalition', 'election', 'minister',
				'opposition', 'vote', 'cabinet', 'spahn', 'merz', 'scholz',
				// Ukrainian equivalents for politik signal in UA-language coverage
				'канцлер', 'парламент', 'уряд', 'коаліц', 'вибор', 'міністр',
			],
			'welt' => [
				'uno', 'un ', 'nato', 'eu ', 'usa', 'china', 'iran', 'nahost', 'gaza',
				'konflikt', 'sanktionen', 'wahl', 'regierung', 'diplomatie',
				// English — geopolitical stories often surface in DW World EN /
				// Google News Politics EN with no German vocabulary.
				'united nations', 'european union', 'washington', 'beijing', 'moscow',
				'kremlin', 'tehran', 'sanctions', 'ceasefire', 'diplomacy', 'summit',
				'middle east', 'gulf', 'taiwan', 'korea', 'syria', 'lebanon', 'turkey',
				'trump', 'biden', 'xi ', 'putin', 'zelensky', 'macron', 'erdogan',
				'санкц', 'дипломат', 'самміт', 'переговор',
			],
			'ukraine' => [
				'ukraine', 'ukrain', 'kyiv', 'kiew', 'russland', 'russisch', 'drohne',
				'front', 'besetzt', 'sanktionen', 'angriff', 'krieg',
				// English equivalents
				'russia', 'russian', 'drone', 'frontline', 'occupied', 'attack', 'war',
				'zelensky', 'putin', 'kremlin', 'invasion', 'shelling', 'missile',
				'україн', 'росі', 'дрон', 'фронт', 'окупов', 'атак', 'війн', 'обстріл', 'ракет',
			],
			'wirtschaft' => [
				'unternehmen', 'tarif', 'inflation', 'preise', 'energie', 'arbeitsmarkt',
				'investition', 'industrie', 'handel', 'konzern', 'verbraucher',
				// English equivalents
				'company', 'tariff', 'price', 'energy', 'job market', 'investment',
				'industry', 'trade', 'corporation', 'consumer', 'recession', 'gdp',
				'інфляц', 'ціни', 'енерг', 'ринок', 'інвестиц', 'промислов', 'торгівл',
			],
			'deutschland' => [
				'gericht', 'polizei', 'gesundheit', 'schule', 'migration', 'wahl',
				'gesetz', 'reform', 'verkehr', 'sicherheit', 'verbraucher',
				// English equivalents
				'court', 'police', 'health', 'school', 'law', 'transport', 'safety',
				'merz', 'scholz', 'spahn', 'cdu', 'spd', 'afd', 'green party',
			],
			'bayern', 'muenchen' => [
				'bayern', 'münchen', 'muenchen', 'landtag', 'stadt', 'polizei', 'verkehr',
				'wohnung', 'miete', 'schule', 'integration', 'migration', 'bürger',
			],
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
		$category = self::canonical_category($category);
		if (! in_array($category, ['muenchen', 'bayern', 'community', 'deutschland'], true)) {
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
		$mentions = self::recent_title_source_mentions(mb_substr($title, 0, 48), DAY_IN_SECONDS);
		$weight = match (true) {
			$mentions >= 5 => 16,
			$mentions >= 3 => 10,
			$mentions >= 2 => 6,
			default => 0,
		};
		return ['mentions' => $mentions, 'weight' => $weight];
	}

	private static function recent_title_source_mentions(string $needle, int $window_seconds): int {
		global $wpdb;
		$needle = trim(mb_strtolower($needle));
		if ($needle === '') {
			return 0;
		}
		static $cache = [];
		$cache_key = md5($needle . '|' . $window_seconds);
		if (array_key_exists($cache_key, $cache)) {
			return (int) $cache[$cache_key];
		}
		$table = $wpdb->prefix . 'epv2_queue';
		$max_id = (int) $wpdb->get_var("SELECT MAX(id) FROM {$table}");
		if ($max_id <= 0) {
			return 0;
		}
		$min_id = max(0, $max_id - 1500);
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT source_id, original_title, created_at FROM {$table} WHERE id >= %d ORDER BY id DESC LIMIT 400",
			$min_id
		));
		$cutoff = time() - max(60, $window_seconds);
		$sources = [];
		foreach ((array) $rows as $row) {
			$created = strtotime((string) ($row->created_at ?? ''));
			if ($created && $created < $cutoff) {
				continue;
			}
			$row_title = self::normalize_title((string) ($row->original_title ?? ''));
			if ($row_title === '' || ! str_contains($row_title, $needle)) {
				continue;
			}
			$source_id = (int) ($row->source_id ?? 0);
			$sources[$source_id > 0 ? (string) $source_id : md5($row_title)] = true;
		}
		$cache[$cache_key] = count($sources);
		return (int) $cache[$cache_key];
	}

	private static function normalize_title(string $title): string {
		$title = mb_strtolower(trim(wp_strip_all_tags($title)));
		$title = preg_replace('/\s+/u', ' ', $title) ?: $title;
		return $title;
	}

	private static function tier_for_score(int $score, string $category = ''): string {
		$card = self::category_scorecard($category);
		return match (true) {
			$score >= (int) $card['a'] => 'A',
			$score >= (int) $card['b'] => 'B',
			$score >= (int) $card['c'] => 'C',
			default => 'D',
		};
	}

	private static function flags_for_score(int $score, int $urgency, int $mentions, string $category, string $risk, string $url, array $breaking_signal = []): array {
		$category = self::canonical_category($category);
		$breaking = ($score >= 72 && $urgency >= 10 && $mentions >= 2 && $risk !== 'high') || ! empty($breaking_signal['breaking']);
		$watch = ! empty($breaking_signal['watch']) || $breaking;
		$top = $score >= 64 && in_array($category, ['politik', 'wirtschaft', 'deutschland', 'ukraine', 'europa', 'welt'], true);
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

	private static function decision_for_score(int $score, string $tier, string $category = '', array $threshold_context = []): string {
		$card = self::category_scorecard($category);
		$dynamic_delta = (int) ($threshold_context['delta'] ?? 0);
		$publish_c = max((int) $card['c'], min(((int) $card['b']) - 1, ((int) $card['publish_c']) + $dynamic_delta));
		return match ($tier) {
			'A' => 'priority',
			'B' => 'strong',
			'C' => $score >= $publish_c ? 'review' : 'low',
			default => 'reject',
		};
	}

	private static function uplift_borderline_newsworthy_score(
		int $score,
		string $category,
		string $text,
		string $url,
		int $priority,
		int $urgency,
		int $practical,
		int $publicImpact,
		int $editorialInterest,
		int $consensusMentions
	): int {
		if ($score >= 40) {
			return $score;
		}

		$category = self::canonical_category($category);
		$seriousCategories = ['politik', 'wirtschaft', 'welt', 'ukraine', 'europa', 'leben-in-deutschland', 'sport', 'kultur', 'community', 'deutschland', 'bayern', 'muenchen'];
		// Heavyweight news categories where a strong signal at score 30-33
		// shouldn't fall off the cliff into D-tier reject. Borderline window
		// for these categories starts at 30 (was 34); for the broader
		// serious set the original 34..39 window applies.
		$heavyweightCategories = ['politik', 'welt', 'ukraine', 'wirtschaft', 'deutschland'];
		$lowerBound = in_array($category, $heavyweightCategories, true) ? 30 : 34;
		if ($score < $lowerBound) {
			return $score;
		}
		if (! in_array($category, $seriousCategories, true)) {
			return $score;
		}

		if (self::looks_like_soft_tabloid_or_evergreen($text)) {
			return $score;
		}

		$hasMeaningfulSignal =
			$publicImpact >= 8
			|| $practical >= 8
			|| $editorialInterest >= 6
			|| $urgency >= 8
			|| $consensusMentions >= 2
			|| $priority >= 6
			|| self::looks_official($url);

		// Fix D: named-politician / named-leader / named-state-org signal.
		// Stories about Merz / Scholz / Trump / Putin / Zelensky scoring
		// 30-33 in heavyweight categories are mainstream news and should
		// reach C/review, not D/reject. The scoring routine already
		// handles general urgency / public_impact, but name-only headlines
		// often miss those weights.
		if (! $hasMeaningfulSignal) {
			$politician_pattern = '/\b('
				. 'merz|scholz|spahn|habeck|baerbock|lindner|s[öo]der|dobrindt|wagenknecht|weidel|chrupalla|'
				. 'trump|biden|harris|vance|musk|rubio|hegseth|'
				. 'putin|zelensky|selensky|selenskyj|sirskij|syrskyj|sirskyi|syrskyi|kremlin|kreml|'
				. 'macron|le\s+pen|m[ée]lenchon|attal|barnier|'
				. 'meloni|salvini|berlusconi|'
				. 'erdogan|netanyahu|netanjahu|abbas|haniyeh|nasrallah|raisi|'
				. 'xi\s+jinping|kim\s+jong[\s-]?un|modi|lula|orb[áa]n|fico|tusk|nawrocki|'
				. 'starmer|sunak|farage|von\s+der\s+leyen|metsola|costa|kallas'
				. ')\b/iu';
			if (preg_match($politician_pattern, $text) === 1) {
				$hasMeaningfulSignal = true;
			}
		}

		if (! $hasMeaningfulSignal) {
			return $score;
		}

		// Lift only as far as the publish_c review threshold for the
		// category — never higher than 40 — so a 30-tier story becomes
		// reviewable, not auto-published. publish_c is 40 by default for
		// the heavyweight set; using max($score, 40) preserves that.
		return max($score, 40);
	}

	private static function soft_low_public_value_penalty(string $text, string $category, int $practical, int $publicImpact, int $communityValue, int $urgency, int $editorialInterest): int {
		$category = self::canonical_category($category);
		if (! in_array($category, ['deutschland', 'bayern', 'muenchen', 'welt', 'sport'], true)) {
			return 0;
		}
		if ($publicImpact >= 8 || $practical >= 8 || $communityValue >= 8 || $urgency >= 8 || $editorialInterest >= 6) {
			return 0;
		}

		$text = mb_strtolower(wp_strip_all_tags($text));
		$animalSoftSignal = preg_match('/\b(wal|wale|delfin|delfine|robbe|robben|hund|hunde|katze|katzen|tierbaby|zoo|tierpark|pudelwohl)\b/u', $text) === 1
			&& preg_match('/\b(rettung|gerettet|befreit|gefunden|transport|pudelwohl|süß|suess|niedlich)\b/u', $text) === 1;
		if (! $animalSoftSignal) {
			return 0;
		}

		$publicValueSignal = preg_match('/\b(artenschutz|naturschutz|umwelt|klima|havarie|öl|oel|seuche|vogelgrippe|behörde|behoerde|polizei|verletzte|gesetz|gericht|verbot|warnung|gefahr|evakuierung)\b/u', $text) === 1;
		return $publicValueSignal ? 0 : -12;
	}

	private static function looks_like_soft_tabloid_or_evergreen(string $text): bool {
		$text = mb_strtolower($text);
		$signals = [
			'fitnessgeheimnis', 'promi', 'celebrity', 'stars', 'sternzeichen', 'horoskop',
			'pokemon', 'sammelkartenspiel', 'pokémon', 'tv und stream', 'wer ist raus',
			'maeusen', 'mäusen', 'look', 'looks like', 'stalkerin', 'fleetwood-mac',
			'nie so ausgesehen', 'secret', 'geheimnis', 'ranking', 'die besten',
			'boulevard', 'palast-drama', 'palace drama', 'titelstreit', 'royal titel',
		];
		foreach ($signals as $signal) {
			if (str_contains($text, $signal)) {
				return true;
			}
		}
		if (
			preg_match('/\b(royal|prince|princess|king|queen|kate|william|harry|meghan|beatrice|eugenie|charles|camilla)\b/u', $text)
			&& preg_match('/\b(titelstreit|drama|skandal|flehen|fleht|bitten um hilfe|palast|palace|familienstreit|gerücht|rumor|aff[aä]re)\b/u', $text)
		) {
			return true;
		}
		return false;
	}

	private static function looks_like_noise(string $title, string $excerpt, string $content, string $url): bool {
		$raw = trim($title . ' ' . $excerpt . ' ' . mb_substr($content, 0, 400));
		if ($raw === '' || mb_strlen($raw) < 24) {
			return true;
		}
		// Lowercase for substring/regex matching so "Wochenarbeitszeit" or
		// "Pressearchiv" do not slip through capitalised — the noise lists
		// are kept in lowercase by convention.
		$text = mb_strtolower($raw);
		// Two distinct lists. Short generic tokens like 'rss' / 'feed' / 'abo'
		// were previously matched as bare substrings, which made them fire on
		// perfectly legitimate articles — e.g. "abo" matched inside "abOUT",
		// rejecting any English-language story containing the word "about".
		// And short tokens against the full URL string matched analytics
		// parameters: DW articles carry `?maca=en-rss-en-eu-...` and were
		// rejected wholesale even when the body was a real geopolitical story
		// ("Russia offers Ukraine May 8-9 ceasefire", "Romania's government
		// collapses ..."). Now:
		//   - long phrases (>= 6 chars) are still substring-matched against
		//     the body text, since they reliably indicate non-news content;
		//   - short tokens are word-boundary matched via regex;
		//   - short tokens are checked against the URL PATH only (not the
		//     query string).
		$long_body_phrases = [
			'newsletter', 'impressum', 'privacy', 'cookie', 'datenschutz',
			'kontakt', 'login', 'registrierung', 'subscribe', 'opml',
			'wir unterstützen', 'spenden', 'donate', 'read more', 'zurück zu startseite', 'startseite',
			'vorlesen', 'zur live map', 'tv und stream', 'watch sports online', 'lade bild',
			'baustellen-übersicht', 'fahrplanänderungen im mvv', 'fragen und antworten',
			'digitaler staat', 'fachtagung', 'symposium', 'top-thema - der sacharow-preis 2023',
			'ursprung der sonne', 'astronomen entdecken', 'zum tod von', 'trauert um',
			'mehr erfahren mehr erfahren', 'mehr erfahren über gründe für störungen',
			'selbstverortung', 'zugehörigkeit', 'pressearchiv', 'presse-archiv',
			'stellenangebot', 'jobbörse', 'teilzeit', 'vollzeit', 'befristet',
			'arbeitsort', 'wochenarbeitszeit', 'vergütungsgruppe', 'entgeltgruppe',
			'diplom- oder masterabschluss', 'psychologischen psychotherapeuten',
			'fachassistent', 'service.bund.de', 'veranstaltungskalender',
		];
		foreach ($long_body_phrases as $phrase) {
			if (str_contains($text, $phrase)) {
				return true;
			}
		}
		// Whole-word match for short German tokens that historically caused
		// false positives as raw substrings.
		if (preg_match('/\b(abo|faq|kongress)\b/u', $text) === 1) {
			return true;
		}
		// Path segment / host check for clearly-non-article URLs. Comparing
		// against PHP_URL_PATH (not the full URL) avoids matching analytics
		// tokens like ?maca=en-rss-... that appear in query strings.
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$path = mb_strtolower((string) wp_parse_url($url, PHP_URL_PATH));
		$url_segment_terms = [
			'/feed', '/rss', '/newsletter', '/login', '/abonnement', '/impressum',
			'/privacy', '/datenschutz', '/kontakt', '/contact', '/jobs', '/karriere',
			'/pressearchiv', '/presse-archiv', '/veranstaltungskalender',
			'/baustellen', '/fahrplan', '/faq',
		];
		foreach ($url_segment_terms as $segment) {
			if ($path !== '' && str_contains($path, $segment)) {
				return true;
			}
		}
		// Whole-host noise: hosts that publish only syndication / archive content.
		if (in_array($host, ['service.bund.de'], true)) {
			return true;
		}
		return false;
	}

	private static function looks_like_non_news_service_page(string $title, string $excerpt, string $content, string $url): bool {
		$text = mb_strtolower(trim(implode(' ', array_filter([
			wp_strip_all_tags($title),
			wp_strip_all_tags($excerpt),
			wp_strip_all_tags($content),
			(string) wp_parse_url($url, PHP_URL_PATH),
		]))));
		if ($text === '') {
			return false;
		}
		$service_path = preg_match('#/(service|services|faq|hilfe|support|fahrplan|fahrplaene|fahrpl[aä]ne|verspaetung|versp[aä]tung|stoerung|st[oö]rung|anmeldung|beratung|sprechstunde|kontakt|leistungen?)(/|$)#u', (string) wp_parse_url($url, PHP_URL_PATH)) === 1;
		$generic_service_copy = preg_match('/\b(fahrplanauskunft|fahrpl[aä]ne|infos zu versp[aä]tung|bleiben sie stets informiert|unsere services informieren|individuelle fahrpl[aä]ne|beratung|sprechstunde|anmeldung|service update|aktuelle informationen)\b/u', $text) === 1;
		$event_signal = preg_match('/\b(streik|warnstreik|ausfall|sperrung|störung|stoerung|heute|morgen|ab\s+\d{1,2}\.|vom\s+\d{1,2}\.|bis\s+\d{1,2}\.|beschlossen|kabinett|gesetz|reform)\b/u', $text) === 1;
		return ($service_path || $generic_service_copy) && ! $event_signal;
	}

	private static function has_rescue_signal(string $text, string $category): bool {
		$category = self::canonical_category($category);
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

	private static function looks_like_hard_reject(string $title, string $excerpt, string $content, string $category, string $url = ''): bool {
		$category = self::canonical_category($category);
		// 2026-05-10: URL включается в headline для матчинга URL-based patterns
		// (BR.de `im-news-ticker-vom-...`, FAZ `liveticker-...` и т.п.).
		// Title мог быть нейтральным («Ukraine-Ticker: ...»), но URL явно
		// помечал контент как live-update — это нужно ловить.
		$headline = trim($title . ' ' . $excerpt . ' ' . mb_substr($content, 0, 300) . ' ' . $url);
		$normalized_title = trim(mb_strtolower(wp_strip_all_tags($title)));
		$body_plain = trim(mb_strtolower(wp_strip_all_tags($excerpt . ' ' . $content)));
		if ($normalized_title === '') {
			return mb_strlen($body_plain) < 80 && ! self::has_rescue_signal($body_plain, $category);
		}
		if (in_array($normalized_title, ['news', 'update', 'ticker', 'live', 'meldung'], true)) {
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
			// Liveblog/Liveticker формат — независимо от рубрики. Это
			// running update-stream без самостоятельной news-substance,
			// AI rewrite таких всегда даёт rejected как low importance.
			// Бережём AI-cost: режем на pre-AI стадии.
			// 2026-05-10: extended — `news-ticker`, `nachrichten-ticker`,
			// `im-news-ticker-vom`, `live-blog`, `aktuelle news vom`
			// (BR.de Ukraine-Ticker passing через эти patterns каждый раз
			// проходил, AI hallucinated смешивая ticker context с другими
			// articles).
			'/\bliveblog\b|\bliveticker\b|\blive[-\s]?ticker\b|\blivereportage\b|\blive[-\s]update\b|\blive[-\s]news[-\s]blog\b|\bnews[-\s]?ticker\b|\bnachrichten[-\s]?ticker\b|\b(?:im[-\s])?news[-\s]ticker[-\s]vom\b|\baktuelle[-\s]news[-\s]vom\b|\bukraine[-\s]ticker\b|\bnahost[-\s]ticker\b|\bnews[-\s]ticker[-\s]vom[-\s]\d/iu',
			// Banal incidents без имени персоны и места значения:
			// «Leiche aus der Spree», «Mensch von Flugzeug erfasst».
			// AI rewrite это всё равно отвергнет как low.
			'/\b(leiche|tote\w*\s+gefunden|leichnam)\s+(aus|in|im|auf|bei)\b/iu',
			'/^notfälle?\s*[:—-]/iu',
			// Sports match recap / fixtures — без news-impact:
			// «X gewinnt Y», «Stuttgart nach Sieg gegen Z», «Magnier gewinnt Giro»,
			// «Lazio - Inter: Tor zum 0:2 durch Sucic in der 39. Minute».
			// Эти регулярно отвергаются на selection — переносим на pre-AI.
			'/^[\p{L}][\p{L}\p{N}\säöüß-]+\s+(gewinnt|gewann|besiegt|verliert|verlor|schlägt|unterliegt|trumpft|bezwingt)\s+/iu',
			'/^\d+:\d+\s+gegen\s+/iu',
			'/\bspieltag\s+(?:der\s+)?(?:bundesliga|2\.\s*liga|3\.\s*liga|champions league|europa league)\b/iu',
			'/\b(?:etappensieg|matchday|matchwinner|abstiegs[-\s]?abgrund|tabellenführer|pole position)\b/iu',
			// Match-event reports: «Tor zum X:Y», «Tor durch [Spieler]», «X-Y in der N. Minute».
			'/\btor\s+(?:zum\s+\d+\s*:\s*\d+|durch\s+\p{Lu})/iu',
			'/\b(?:in\s+der\s+\d{1,2}\.\s+minute|nachspielzeit|elfmeter|freistoß|eigentor|gelb-rote\s+karte|rote\s+karte\s+für)\b/iu',
			// Match-card titles: «Lazio - Inter: …» (two team names with dash, plus goal/sport context after).
			'/^\p{Lu}[\p{L}\p{N}\säöüß-]{2,30}\s*[-–]\s*\p{Lu}[\p{L}\p{N}\säöüß-]{2,30}\s*[:—]\s*(?:tor\b|spielbericht|liveticker|live-blog|kicker|highlights)/iu',
			// Tourism/lifestyle puff: «Wunder von X», «Mode-Metropole», «Reise-Tipps».
			'/\b(?:das\s+wunder\s+von|mode-metropole|reise[-\s]?tipps?|wochenend[-\s]?tipps?|sehenswürdigkeit\w*|gastro-?tipp\w*)\b/iu',
			// Photo gallery markers (operator feedback 2026-05-11): photo
			// galleries без news-substance. Strict tightening — only exact
			// phrases that are unambiguously photo gallery headers, not just
			// "bilder" in random context.
			'/\b(?:die\s+welt\s+in\s+bildern|bildersafari|bilder\s+des\s+tages|fotostrecke|bildergalerie|bildgeschichte\s+der\s+woche)\b/iu',
			// Celebrity Familienfotos pattern — specific (Sandra Bullock
			// 2026-05-11 case): «X teilt Familienfotos» / «Familienfotos
			// von X». Very narrow — only fires when "teilt Familienfotos"
			// or "Familienfotos von" — won't false-positive on legit news.
			'/\bteilt\s+familienfotos?\b|\bfamilienfotos?\s+(?:auf\s+instagram|von\s+[A-ZÄÖÜ])/iu',
			// Glossen/Kolumnen/Kommentare — opinion, не news.
			'/^(?:kommentar|kolumne|glosse|leitartikel|editorial|meinung)\s*[:—-]/iu',
			// Lottery / numerical-only pages: «Zu den aktuellen Lottozahlen»,
			// «Eurojackpot Gewinnzahlen», etc. Это просто таблица чисел,
			// AI rewrite на нём всегда даёт «too thin for autopublish».
			'/\b(?:lottozahl\w*|gewinnzahl\w*|eurojackpot|gluecksspirale|glücksspirale|spiel\s*77|super\s*6|klassenlotterie)\b/iu',
			'/^zu\s+den\s+(?:aktuellen|heutigen|neuen)\s+/iu',
			// Weather forecast pages — без news-substance.
			'/\b(?:wetterbericht|wetter[-\s]?vorhersage|wetter[-\s]?prognose|aktuelles\s+wetter)\b/iu',
			// Stock-ticker / market-numbers stubs: «DAX schliesst bei …», «S&P 500».
			'/^(?:dax|mdax|sdax|tecdax|euro\s+stoxx|nikkei|s&p\s*500|dow\s*jones|nasdaq)\s+(?:schl(?:ie|o)ss|er(?:ö|oe)ffnet|steigt|fällt|fallt|notiert)/iu',
			// Pure horoscope / TV programme listings.
			'/\b(?:horoskop|fernsehprogramm|tv-programm|sendetermine?)\b/iu',
			// Daily TV news bulletins (ZDF heute, Tagesschau vom DD.MM): это
			// transcript ежедневного выпуска, AI rewrite на нём бесполезен.
			'/\b(?:zdf\s+heute\s+sendung|heute[\s-]+sendung\s+vom|tagesschau\s+vom\s+\d|sendung\s+vom\s+\d{1,2}\.\s*(?:januar|februar|m(?:ä|ae)rz|april|mai|juni|juli|august|september|oktober|november|dezember))\b/iu',
			// LIVE!-prefix sport / news ticker: «LIVE! Wolfsburg schnuppert…».
			'/^live[!\:\s]/iu',
			// Sport recap verbs пропущенные раньше (wahrt, holt, fixiert,
			// kassiert, sichert, schießt, knackt, dreht, dominiert, dreht
			// auf, kommt zurück) — без team-first структуры. Между verb и
			// контекстом допускается до 3 слов наречий (glanzlos, knapp,
			// souverän, mühelos, mühsam):
			'/\b(?:wahrt|holt|fixiert|kassiert|sichert\s+sich|sich\s+sichert|sch(?:ie|ie)(?:ß|ss)t|knackt|dreht|dominiert|patzt|stolpert|dreht\s+auf|kommt\s+zurück)(?:\s+\p{L}+){0,3}\s+(?:seine?\s+)?(?:cl|el|champions|europa|bundesliga|liga|sieg|tabellenf(?:ü|ue)hrung|tor|chancen|punkt|spieltag|tr(?:ä|ae)ume?)/iu',
			// Sport-context vocabulary (CL-Träume, EL-Träume, Liga-Träume,
			// Bundesliga-Träume) сама по себе — sport recap.
			'/\b(?:cl|el|champions[-\s]?league|europa[-\s]?league|bundesliga|liga)[-\s]?tr(?:ä|ae)um(?:e|en)\b/iu',
			// Sport-recap fallback — match-result в любом месте title:
			'/\b(?:besiegt|bezwingt|schlägt|trumpft|erringt|unterliegt|verliert\s+gegen)\s+(?:den\s+|die\s+|das\s+)?\p{Lu}/iu',
			// Match-fixture preview/recap: «Spielbericht», «Spielanalyse»,
			// «Halbzeit-Analyse».
			'/\b(?:spielbericht|spielanalyse|halbzeit[\s-]?analyse|nachholspiel|abstiegskampf)\b/iu',
			// Cross-lang weather (UA/RU): «Циклони насуваються», «Погода»,
			// «Похолодання», «Потеплення».
			'/\b(?:погода|циклон\w*|похолодан\w*|потеплін\w*|опадк\w*|опади|зливи|сніго\w+|снігопад\w*|туман\w+|туманно|штормове\s+попередження|загроза\s+негоди)\b/iu',
			// Lottery/Eurojackpot ZDF/landing pages — URL-based fallback handled separately.
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $headline)) {
				return true;
			}
		}
			if ($category === 'muenchen' && preg_match('/übersicht|mehr erfahren/u', $headline)) {
				return true;
			}
		return false;
	}

	private static function sport_live_fixture_block(string $title, string $excerpt, string $content, string $category): string {
		$category = self::canonical_category($category);
		if ($category !== 'sport') {
			return '';
		}

		$text = trim(mb_strtolower(wp_strip_all_tags($title . ' ' . $excerpt . ' ' . mb_substr($content, 0, 500))));
		if ($text === '') {
			return '';
		}

		$fixtureSignal = preg_match('/\b(livereportage|liveticker|live[-\s]?ticker|liveblog|tv und stream|übertragung|uebertragung|wo läuft|wo laeuft|anpfiff|heute[, ]+\d{1,2}(?::\d{2})?\s*uhr)\b/u', $text) === 1;
		if (! $fixtureSignal) {
			return '';
		}

		$sportSignal = preg_match('/\b(fc bayern|paris saint-germain|psg|bundesliga|champions league|europa league|conference league|dfb|uefa|spiel|match|halbfinale|viertelfinale|achtelfinale|rückspiel|hinspiel)\b/u', $text) === 1;
		if (! $sportSignal) {
			return '';
		}

		$actualNewsSignal = preg_match('/\b(gewann|verlor|sieg|niederlage|finale erreicht|ausgeschieden|verletz|transfer|entlassen|rücktritt|urteil|ermittlung|skandal|strafe|rekord|entscheidung|beschlossen)\b/u', $text) === 1;
		if ($actualNewsSignal) {
			return '';
		}

		return 'низкоценная спортивная live/fixture страница без самостоятельного новостного результата';
	}

	private static function telegram_community_promo_block(string $title, string $excerpt, string $content, string $url, string $category): string {
		$host = (string) wp_parse_url($url, PHP_URL_HOST);
		$path = (string) wp_parse_url($url, PHP_URL_PATH);
		$isTelegram = str_contains($host, 't.me') || str_contains($host, 'telegram.me');
		if (! $isTelegram) {
			return '';
		}

			$category = self::canonical_category($category);
		if (! in_array($category, ['community', 'leben-in-deutschland'], true)) {
			return '';
		}

		$text = trim(mb_strtolower(wp_strip_all_tags($title . ' ' . $excerpt . ' ' . $content)));
		if ($text === '') {
			return 'telegram/community promo without reader-facing attribution';
		}

		$promoSignal =
			preg_match('/\b(telegram-kanal|telegram channel|kanal|channel|join|subscribe|follow|kommentaren|comments|stellt eure fragen|ставте свої питання|задавайте вопросы|frage der woche|offenes forum|wir beantworten|мы ответим|підписуйтесь|подписывайтесь)\b/ui', $text) === 1;
		if (! $promoSignal) {
			return '';
		}

		$serviceSignal =
			preg_match('/\b(uhrumstellung|sommerzeit|winterzeit|jobcenter|wohngeld|kindergeld|krankenkasse|dokumente|aufenthalt|integrationskurs|deutschkurs|arbeitserlaubnis|schule|kita|visa|виплати|пособие|документы|жилье|страховка)\b/ui', $text) === 1;
		if ($serviceSignal) {
			return '';
		}

		$handle = '';
		if (preg_match('#^/(?:s/)?([^/]+)/#', $path, $m) === 1) {
			$handle = sanitize_text_field((string) ($m[1] ?? ''));
		}
		$hasExplicitHandle = $handle !== '' && (
			str_contains($text, '@' . mb_strtolower($handle))
			|| str_contains($text, mb_strtolower($handle))
		);
		if ($hasExplicitHandle) {
			return '';
		}

		return 'telegram/community promo without explicit channel naming or reader-facing attribution';
	}

	private static function old_story_penalty(string $text): int {
		$currentYear = (int) gmdate('Y');
		$old_years = 0;
		if (preg_match_all('/\b(20\d{2})\b/u', $text, $matches)) {
			foreach ((array) ($matches[1] ?? []) as $year) {
				$year = (int) $year;
				if ($year > 2000 && $year < ($currentYear - 1)) {
					$old_years++;
				}
			}
		}
		if ($old_years === 0) {
			return 0;
		}
		$archive_context = preg_match('/\b(archiv|rückblick|rueckblick|historisch|damals|vor \d+ jahren|jahrestag|chronik|wiederholung|best of)\b/u', $text) === 1;
		if ($archive_context) {
			return -10;
		}
		if ($old_years >= 3 && preg_match('/\b(heute|aktuell|neue|neuer|beschluss|urteil|prozess|wahl|angriff|reform|gesetz)\b/u', $text) !== 1) {
			return -5;
		}
		return 0;
	}
}
