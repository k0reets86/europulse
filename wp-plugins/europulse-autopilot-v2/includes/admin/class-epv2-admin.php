<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Admin {
	public static function register(): void {
		add_action('admin_menu', [self::class, 'menus']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
		add_action('admin_post_epv2_save_settings', [self::class, 'save_settings']);
		add_action('admin_post_epv2_save_source', [self::class, 'save_source']);
		add_action('admin_post_epv2_toggle_source', [self::class, 'toggle_source']);
		add_action('admin_post_epv2_delete_source', [self::class, 'delete_source']);
		add_action('admin_post_epv2_import_recommended_sources', [self::class, 'import_recommended_sources']);
		add_action('admin_post_epv2_delete_queue_items', [self::class, 'delete_queue_items']);
		add_action('admin_post_epv2_clear_queue', [self::class, 'clear_queue']);
		add_action('admin_post_epv2_update_queue_category', [self::class, 'update_queue_category']);
		add_action('admin_post_epv2_run_collect', [self::class, 'run_collect']);
		add_action('admin_post_epv2_run_process', [self::class, 'run_process']);
		add_action('admin_post_epv2_run_publish', [self::class, 'run_publish']);
		add_action('admin_post_epv2_pause_automation', [self::class, 'pause_automation']);
		add_action('admin_post_epv2_resume_automation', [self::class, 'resume_automation']);
		add_action('admin_post_epv2_reset_stats', [self::class, 'reset_stats']);
		add_action('admin_post_epv2_prune_queue', [self::class, 'prune_queue']);
		add_action('admin_post_epv2_queue_to_publish', [self::class, 'queue_to_publish']);
		add_action('admin_post_epv2_publish_now', [self::class, 'publish_now']);
		add_action('admin_post_epv2_review_ready_publish', [self::class, 'review_ready_publish']);
		add_action('admin_post_epv2_save_review', [self::class, 'save_review']);
		add_action('admin_post_epv2_regenerate_field', [self::class, 'regenerate_field']);
		add_action('admin_post_epv2_manual_generate', [self::class, 'manual_generate']);
		add_action('admin_post_epv2_manual_submit', [self::class, 'manual_submit']);
		add_action('admin_post_epv2_test_source', [self::class, 'test_source']);
		add_action('wp_ajax_epv2_queue_snapshot', [self::class, 'queue_snapshot']);
	}

	public static function enqueue_assets(string $hook): void {
		if (strpos($hook, 'epv2') === false) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script('jquery-core', self::admin_script());
	}

	public static function menus(): void {
		$cap = 'manage_europulse_autopilot';
		add_menu_page('EuroPulse AutoPilot', 'AutoPilot', $cap, 'epv2-dashboard', [self::class, 'dashboard'], 'dashicons-rss', 3);
		add_submenu_page('epv2-dashboard', 'Обзор', 'Обзор', $cap, 'epv2-dashboard', [self::class, 'dashboard']);
		add_submenu_page('epv2-dashboard', 'Источники', 'Источники', $cap, 'epv2-sources', [self::class, 'sources']);
		add_submenu_page('epv2-dashboard', 'Очередь', 'Очередь', $cap, 'epv2-queue', [self::class, 'queue']);
		add_submenu_page('epv2-dashboard', 'Настройки', 'Настройки', $cap, 'epv2-settings', [self::class, 'settings']);
		add_submenu_page('epv2-dashboard', 'Ручной режим', 'Ручной режим', $cap, 'epv2-manual', [self::class, 'manual']);
		add_submenu_page('epv2-dashboard', 'Проверка материала', 'Проверка материала', $cap, 'epv2-review', [self::class, 'review']);
		add_submenu_page('epv2-dashboard', 'Логи', 'Логи', $cap, 'epv2-logs', [self::class, 'logs']);
		add_submenu_page('epv2-dashboard', 'Запуски', 'Запуски', $cap, 'epv2-runs', [self::class, 'runs']);
	}

	public static function dashboard(): void {
		$stats = EPV2_Stats::dashboard();
		$selected_category = sanitize_title((string) ($_GET['stats_category'] ?? ''));
		$range_stats = EPV2_Stats::dashboard_ranges($selected_category !== '' ? $selected_category : null);
		$ai_usage = EPV2_Stats::ai_usage_today();
		$budget = EPV2_Budget_Manager::budget_state();
		$time_mode = EPV2_Time_Planner::current_mode_label();
		$breaking_watch = EPV2_Time_Planner::has_breaking_watch();
		$automation_paused = EPV2_Jobs::automation_paused();
		$progress = get_option('epv2_collect_progress', []);
		$last_collect = EPV2_Runs::latest('collect');
		$last_payload = $last_collect && ! empty($last_collect->payload) ? json_decode((string) $last_collect->payload, true) : [];
		$issues = self::latest_human_issues();
		echo '<div class="wrap"><h1>EuroPulse AutoPilot</h1>';
		echo '<p>Текущий контракт сайта загружен для языков: <strong>' . esc_html(implode(', ', EPV2_Site_Profile::get_languages())) . '</strong></p>';
		echo '<div style="margin:16px 0;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:980px">';
		echo '<h2 style="margin:0 0 8px">Автоматический режим</h2>';
		echo '<p style="margin:0 0 12px;color:#50575e">Когда включён <strong>Пуск</strong>, плагин сам работает по расписанию. Когда включена <strong>Пауза</strong>, автопоиск, автообработка и автопубликация остановлены.</p>';
		echo '<p style="margin:0 0 8px;color:#50575e">Сейчас: <strong style="color:' . ($automation_paused ? '#b45309' : '#15803d') . '">' . ($automation_paused ? 'Пауза' : 'Пуск') . '</strong></p>';
		echo '<p style="margin:0">';
		if ($automation_paused) {
			echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_resume_automation'), 'epv2_resume_automation')) . '">Пуск</a>';
		} else {
			echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_pause_automation'), 'epv2_pause_automation')) . '">Пауза</a>';
		}
		echo '</p></div>';
		echo '<div style="margin:16px 0;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:980px">';
		echo '<h2 style="margin:0 0 8px">Ручные сервисные действия</h2>';
		echo '<p style="margin:0 0 12px;color:#50575e">Эти кнопки не включают автоматику. Они вручную запускают один отдельный этап один раз.</p>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_run_collect'), 'epv2_run_collect')) . '">Собрать кандидатов один раз</a> ';
		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_run_process'), 'epv2_run_process')) . '">Обработать очередь один раз</a> ';
		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_run_publish'), 'epv2_run_publish')) . '">Проверить публикацию один раз</a> ';
		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_prune_queue'), 'epv2_prune_queue')) . '">Почистить очередь</a> ';
		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_reset_stats'), 'epv2_reset_stats')) . '" onclick="return confirm(\'Сбросить сегодняшние счётчики?\')">Сбросить счётчики</a>';
		echo '</p></div>';
		if (! empty($issues)) {
			echo '<h2>Понятные проблемы</h2><table class="widefat striped" style="max-width:980px;margin-bottom:16px"><thead><tr><th>Где</th><th>Что происходит</th></tr></thead><tbody>';
			foreach ($issues as $issue) {
				echo '<tr><td>' . esc_html($issue['module']) . '</td><td>' . esc_html($issue['message']) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if (is_array($progress) && ! empty($progress)) {
			echo '<h2>Прогресс сбора</h2><table class="widefat striped" style="max-width:760px;margin-bottom:16px"><tbody>';
			echo '<tr><th>Статус</th><td>' . esc_html(self::run_status_label((string) ($progress['status'] ?? 'idle'))) . '</td></tr>';
			echo '<tr><th>Источников обработано</th><td>' . esc_html((string) ($progress['processed_sources'] ?? 0)) . ' / ' . esc_html((string) ($progress['total_sources'] ?? 0)) . '</td></tr>';
			echo '<tr><th>Текущий источник</th><td>' . esc_html((string) ($progress['current_source'] ?? '')) . '</td></tr>';
			echo '<tr><th>Создано материалов</th><td>' . esc_html((string) ($progress['collected_items'] ?? 0)) . '</td></tr>';
			echo '<tr><th>Ошибки</th><td>' . esc_html((string) ($progress['errors'] ?? 0)) . '</td></tr>';
			echo '</tbody></table>';
		}
		if ($last_collect) {
			echo '<h2>Последний запуск сбора</h2><table class="widefat striped" style="max-width:760px;margin-bottom:16px"><tbody>';
			echo '<tr><th>Статус</th><td>' . esc_html(self::run_status_label((string) $last_collect->status)) . '</td></tr>';
			echo '<tr><th>Источники</th><td>' . esc_html((string) ($last_payload['processed_sources'] ?? 0)) . ' / ' . esc_html((string) ($last_payload['total_sources'] ?? 0)) . '</td></tr>';
			echo '<tr><th>Создано материалов</th><td>' . esc_html((string) ($last_payload['collected_items'] ?? $last_collect->item_count)) . '</td></tr>';
			echo '<tr><th>Ошибки</th><td>' . esc_html((string) ($last_payload['errors'] ?? $last_collect->error_count)) . '</td></tr>';
			echo '</tbody></table>';
		}
		$category_options = EPV2_Taxonomy_Map::categories();
		echo '<h2>Статистика</h2>';
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:0 0 12px">';
		echo '<input type="hidden" name="page" value="epv2-dashboard">';
		echo '<div><label for="epv2-stats-category"><strong>Рубрика</strong></label><br><select id="epv2-stats-category" name="stats_category"><option value="">Все</option>';
		foreach ($category_options as $slug => $label) {
			echo '<option value="' . esc_attr($slug) . '"' . selected($selected_category, $slug, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select></div><div><button class="button button-secondary" type="submit">Показать</button></div></form>';
		echo '<table class="widefat striped" style="max-width:980px;margin-bottom:16px"><thead><tr><th>Период</th><th>Публикации</th><th>Публикации по рубрике</th><th>Сбор</th><th>Обработка</th><th>Дубли</th><th>Ошибки</th><th>AI токены</th><th>Посетители</th><th>Просмотры</th></tr></thead><tbody>';
		foreach (['day' => 'День', 'week' => 'Неделя', 'month' => 'Месяц', 'year' => 'Год'] as $key => $label) {
			$row = $range_stats[$key] ?? [];
			echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html((string) ($row['published'] ?? 0)) . '</td><td>' . esc_html((string) ($row['published_filtered'] ?? 0)) . '</td><td>' . esc_html((string) ($row['collected'] ?? 0)) . '</td><td>' . esc_html((string) ($row['rewritten'] ?? 0)) . '</td><td>' . esc_html((string) ($row['duplicates'] ?? 0)) . '</td><td>' . esc_html((string) ($row['errors'] ?? 0)) . '</td><td>' . esc_html((string) ($row['ai_tokens'] ?? 0)) . '</td><td>' . esc_html((string) ($row['visitors'] ?? 0)) . '</td><td>' . esc_html((string) ($row['pageviews'] ?? 0)) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ($ai_usage !== []) {
			echo '<h2>AI вызовы за сегодня</h2><table class="widefat striped" style="max-width:760px;margin-bottom:16px"><thead><tr><th>Провайдер</th><th>Модель</th><th>Вызовы</th><th>Токены</th></tr></thead><tbody>';
			foreach ($ai_usage as $provider => $models) {
				foreach ((array) $models as $model => $row) {
					echo '<tr><td>' . esc_html((string) $provider) . '</td><td>' . esc_html((string) $model) . '</td><td>' . esc_html((string) ((int) ($row['requests'] ?? 0))) . '</td><td>' . esc_html((string) ((int) ($row['tokens'] ?? 0))) . '</td></tr>';
				}
			}
			echo '</tbody></table>';
		}
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ($stats as $key => $value) {
			if ((string) $key === 'ai_cost') {
				continue;
			}
			echo '<tr><th>' . esc_html(self::metric_label((string) $key)) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
		}
		echo '<tr><th>AI бюджет</th><td>' . esc_html((string) EPV2_Settings::get('ai_budget_mode', 'normal')) . ' / ' . esc_html((string) EPV2_Settings::get('ai_selection_strictness', 'medium')) . '</td></tr>';
		echo '<tr><th>Автоматический режим</th><td>' . esc_html($automation_paused ? 'Пауза' : 'Пуск') . '</td></tr>';
		echo '<tr><th>Режим дня (Берлин)</th><td>' . esc_html($time_mode) . '</td></tr>';
		echo '<tr><th>Breaking watch</th><td>' . esc_html($breaking_watch ? 'Активен' : 'Нет') . '</td></tr>';
		echo '<tr><th>AI запросы сегодня</th><td>' . esc_html((string) $budget['rewritten_today']) . ' / ' . esc_html((string) $budget['request_limit']) . '</td></tr>';
		echo '<tr><th>AI токены сегодня</th><td>' . esc_html((string) $budget['tokens_today']) . ' / ' . esc_html((string) $budget['token_limit']) . '</td></tr>';
		echo '</tbody></table></div>';
	}

	public static function sources(): void {
		$sources = EPV2_Sources::all(false);
		$coverage = EPV2_Sources::coverage();
		echo '<div class="wrap"><h1>Источники</h1>';
		if (! empty($_GET['tested'])) {
			$result = get_transient('epv2_source_test_' . get_current_user_id());
			if (is_array($result)) {
				echo '<div class="notice notice-info"><p><strong>Результат проверки источника:</strong> ' . esc_html((string) ($result['message'] ?? ($result['success'] ? 'Источник доступен.' : 'Ошибка'))) . '</p>';
				if (! empty($result['preview']) && is_array($result['preview'])) {
					echo '<pre style="white-space:pre-wrap;max-width:1100px;overflow:auto">' . esc_html(wp_json_encode($result['preview'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
				} elseif (! empty($result['data'])) {
					echo '<pre style="white-space:pre-wrap;max-width:1100px;overflow:auto">' . esc_html(wp_json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
				}
				echo '</div>';
			}
		}
		echo '<p><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_import_recommended_sources'), 'epv2_import_recommended_sources')) . '">Импортировать рекомендованные источники</a></p>';
		echo '<p>Здесь не зашиты постоянные URL. Источники, RSS, Google News, scrape-адреса и их активность управляются только из дашборда.</p>';
		echo '<h2>Покрытие рубрик</h2><table class="widefat striped" style="max-width:760px;margin-bottom:24px"><thead><tr><th>Рубрика</th><th>Всего источников</th><th>Активных</th></tr></thead><tbody>';
		foreach ($coverage as $row) {
			echo '<tr><td>' . esc_html($row['label']) . '</td><td>' . (int) $row['total'] . '</td><td>' . (int) $row['active'] . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>Добавить источник</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('epv2_save_source');
		echo '<input type="hidden" name="action" value="epv2_save_source">';
		echo '<table class="form-table"><tbody>';
		self::row('Название', '<input name="name" class="regular-text" required>');
		self::row('Тип', self::source_type_select('type'));
		self::row('URL', '<input name="url" class="regular-text" required>');
		self::row('Язык', '<input name="language" value="de" class="small-text">');
		self::row('Целевая рубрика', self::category_select('category_bias'));
		self::row('Приоритет', '<input name="priority" value="5" type="number" min="1" max="10" class="small-text">');
		self::row('Интервал опроса (сек)', '<input name="fetch_interval" value="1800" type="number" min="300" class="small-text">');
		self::row('Риск', '<select name="risk_level"><option>safe</option><option selected>low</option><option>moderate</option><option>high</option></select>');
		self::row('Parse rules (JSON)', '<textarea name="parse_rules_json" rows="6" class="large-text code" placeholder=\'{"listing_xpath":"//main//a[@href]"}\'></textarea>');
		self::row('Заметки / лицензия', '<textarea name="notes" rows="5" class="large-text"></textarea>');
		self::row('Статус', '<label><input type="checkbox" name="is_active" checked> включен</label>');
		echo '</tbody></table>';
		submit_button('Сохранить источник');
		echo '</form>';

		echo '<h2>Текущие источники</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Название</th><th>Тип</th><th>Язык</th><th>Категория</th><th>Приоритет</th><th>Статус</th><th>Последняя ошибка</th><th>Действия</th></tr></thead><tbody>';
		foreach ($sources as $source) {
			echo '<tr>';
			echo '<td>' . (int) $source->id . '</td><td>' . esc_html($source->name) . '</td><td>' . esc_html($source->type) . '</td><td>' . esc_html($source->language) . '</td><td>' . esc_html(self::category_label((string) $source->category_bias)) . '</td><td>' . (int) $source->priority . '</td><td>' . esc_html(self::source_status_label((bool) $source->is_active)) . '</td><td>' . esc_html(wp_trim_words((string) $source->last_error, 10, '')) . '</td><td>';
			echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_toggle_source&id=' . (int) $source->id), 'epv2_toggle_source_' . (int) $source->id)) . '">Вкл/выкл</a> ';
			echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_test_source&id=' . (int) $source->id), 'epv2_test_source_' . (int) $source->id)) . '">Проверить</a> ';
			echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_delete_source&id=' . (int) $source->id), 'epv2_delete_source_' . (int) $source->id)) . '" onclick="return confirm(\'Удалить источник?\')">Удалить</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function queue(): void {
		EPV2_Resilience_Manager::cleanup();
		EPV2_Jobs::maybe_kick_pipeline();
		EPV2_Queue::prune_rejected(1440);
		$orderby = sanitize_key((string) ($_GET['orderby'] ?? 'created_at'));
		$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
		$state_filter = sanitize_key((string) ($_GET['state_filter'] ?? ''));
		$category_filter = sanitize_title((string) ($_GET['category_filter'] ?? ''));
		$items = EPV2_Queue::get_items([
			'limit' => 200,
			'state' => $state_filter !== '' ? $state_filter : null,
		]);
		if ($category_filter !== '') {
			$items = array_values(array_filter($items, static function ($item) use ($category_filter) {
				$categories = self::normalize_selected_categories((string) ($item->category_final ?: $item->category_proposed));
				return in_array($category_filter, $categories, true);
			}));
		}
		$items = self::sort_queue_items($items, $orderby, $order);
		$category_options = EPV2_Taxonomy_Map::categories();
		$automation_paused = EPV2_Jobs::automation_paused();
		$progress = get_option('epv2_collect_progress', []);
		$next_collect = EPV2_Jobs::next_collect_timestamp();
		$next_publish = null;
		if (! $automation_paused && ! $next_collect) {
			EPV2_Jobs::maybe_schedule();
			$next_collect = EPV2_Jobs::next_collect_timestamp();
		}
		echo '<div class="wrap"><h1>Очередь</h1>';
		$queue_notice = sanitize_key((string) ($_GET['queue_notice'] ?? ''));
		if ($queue_notice !== '') {
			$message = match ($queue_notice) {
				'not_publish_ready' => 'Материал не дотянул до полного publish-grade и не может быть переведён в готово к публикации.',
				'publish_blocked' => 'Публикация остановлена: материал не прошёл полный quality gate.',
				default => '',
			};
			if ($message !== '') {
				echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
			}
		}
		echo '<div id="epv2-queue-summary" style="margin:12px 0 16px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:980px">';
		echo '<h2 style="margin:0 0 8px">Следующий сбор</h2>';
		echo '<p style="margin:0 0 8px;color:#50575e">Статус: <strong style="color:' . ($automation_paused ? '#b45309' : '#15803d') . '">' . ($automation_paused ? 'Пауза' : 'Работает') . '</strong></p>';
		if (! $automation_paused && $next_collect) {
			$collect_running = is_array($progress ?? null) && (($progress['status'] ?? '') === 'running');
			$remaining = max(0, (int) $next_collect - time());
			$hours = (int) floor($remaining / HOUR_IN_SECONDS);
			$minutes = (int) floor(($remaining % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
			$seconds = (int) ($remaining % MINUTE_IN_SECONDS);
			$initial_countdown = $hours > 0
				? sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds)
				: sprintf('%02d:%02d', $minutes, $seconds);
			echo '<p style="margin:0 0 8px">До нового сбора: <strong id="epv2-next-collect-countdown" data-running="' . ($collect_running ? '1' : '0') . '" data-target="' . esc_attr((string) ($next_collect * 1000)) . '" data-interval="' . esc_attr((string) (max(5, (int) EPV2_Settings::get('collect_interval_minutes', 30)) * MINUTE_IN_SECONDS * 1000)) . '"><span id="epv2-next-collect-timer">' . esc_html($initial_countdown) . '</span></strong></p>';
			echo '<p style="margin:0;color:#50575e;font-size:12px">Следующий автосбор: ' . esc_html(wp_date('d.m H:i', $next_collect)) . '.</p>';
		} elseif ($automation_paused) {
			echo '<p style="margin:0;color:#50575e">Автосбор и автопубликация приостановлены. Обратный отсчёт возобновится после нажатия <strong>Пуск</strong> на странице обзора.</p>';
		} else {
			echo '<p style="margin:0;color:#50575e">Следующий автосбор пока не запланирован.</p>';
		}
		echo '</div>';
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:0 0 12px">';
		echo '<input type="hidden" name="page" value="epv2-queue">';
		echo '<div><label for="epv2-state-filter"><strong>Статус</strong></label><br><select id="epv2-state-filter" name="state_filter"><option value="">Все</option>';
		foreach (self::queue_state_options() as $state => $label) {
			echo '<option value="' . esc_attr($state) . '"' . selected($state_filter, $state, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select></div>';
		echo '<div><label for="epv2-category-filter"><strong>Категория</strong></label><br><select id="epv2-category-filter" name="category_filter"><option value="">Все</option>';
		foreach ($category_options as $slug => $label) {
			echo '<option value="' . esc_attr($slug) . '"' . selected($category_filter, $slug, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select></div>';
		echo '<div><label for="epv2-orderby"><strong>Сортировка</strong></label><br><select id="epv2-orderby" name="orderby">';
		foreach (self::queue_sort_options() as $value => $label) {
			echo '<option value="' . esc_attr($value) . '"' . selected($orderby, $value, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select></div>';
		echo '<div><label for="epv2-order"><strong>Порядок</strong></label><br><select id="epv2-order" name="order"><option value="desc"' . selected($order, 'desc', false) . '>По убыванию</option><option value="asc"' . selected($order, 'asc', false) . '>По возрастанию</option></select></div>';
		echo '<div><button class="button button-secondary" type="submit">Применить</button></div>';
		echo '</form>';
		$live_queue_items = array_values(array_filter($items, static fn($item) => in_array((string) $item->state, ['new', 'processing_de', 'retry_process', 'reserve', 'error'], true)));
		$review_queue_items = array_values(array_filter($items, static fn($item) => (string) $item->state === 'ready_review'));
		$publish_queue_items = array_values(array_filter($items, static fn($item) => (string) $item->state === 'ready_publish'));
		$published_items = array_values(array_filter($items, static fn($item) => (string) $item->state === 'published'));
		$published_recent_items = array_slice($published_items, 0, 12);
		$published_archive_items = array_slice($published_items, 12);
		echo '<form id="epv2-bulk-delete-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
		wp_nonce_field('epv2_delete_queue_items');
		echo '<input type="hidden" name="action" value="epv2_delete_queue_items">';
		echo '<input type="hidden" name="ids_csv" id="epv2-bulk-delete-ids" value="">';
		echo '<button class="button button-secondary" type="submit" onclick="var ids=[...document.querySelectorAll(\'.epv2-queue-check:checked\')].map(function(cb){return cb.value;}); if(!ids.length){alert(\'Выберите материалы для удаления\'); return false;} document.getElementById(\'epv2-bulk-delete-ids\').value=ids.join(\',\'); return confirm(\'Удалить выбранные материалы?\');">Удалить выбранные</button>';
		echo '</form>';
		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_clear_queue'), 'epv2_clear_queue')) . '" onclick="return confirm(\'Очистить всю очередь?\')">Очистить очередь</a>';
		echo '<div id="epv2-queue-blocks">';
		$next_publish = $publish_queue_items !== [] ? EPV2_Queue::next_ready_publish_timestamp() : null;
		echo self::queue_blocks_html($live_queue_items, $review_queue_items, $publish_queue_items, $published_recent_items, $published_archive_items, $orderby, $order, $state_filter, $category_filter, $automation_paused, $next_publish);
		echo '</div>';
		echo '</div>';
	}

	public static function queue_snapshot(): void {
		if (! current_user_can('manage_europulse_autopilot')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		EPV2_Resilience_Manager::cleanup();
		EPV2_Jobs::maybe_kick_pipeline();
		$progress = get_option('epv2_collect_progress', []);
		$orderby = sanitize_key((string) ($_GET['orderby'] ?? 'created_at'));
		$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
		$state_filter = sanitize_key((string) ($_GET['state_filter'] ?? ''));
		$category_filter = sanitize_title((string) ($_GET['category_filter'] ?? ''));
		$items = EPV2_Queue::get_items([
			'limit' => 200,
			'state' => $state_filter !== '' ? $state_filter : null,
		]);
		if ($category_filter !== '') {
			$items = array_values(array_filter($items, static function ($item) use ($category_filter) {
				$categories = self::normalize_selected_categories((string) ($item->category_final ?: $item->category_proposed));
				return in_array($category_filter, $categories, true);
			}));
		}
		$items = self::sort_queue_items($items, $orderby, $order);
		$live_queue_items = array_values(array_filter($items, static fn($item) => in_array((string) $item->state, ['new', 'processing_de', 'retry_process', 'reserve', 'error'], true)));
		$review_queue_items = array_values(array_filter($items, static fn($item) => (string) $item->state === 'ready_review'));
		$publish_queue_items = array_values(array_filter($items, static fn($item) => (string) $item->state === 'ready_publish'));
		$published_items = array_values(array_filter($items, static fn($item) => (string) $item->state === 'published'));
		$published_recent_items = array_slice($published_items, 0, 12);
		$published_archive_items = array_slice($published_items, 12);
		$automation_paused = EPV2_Jobs::automation_paused();
		$next_collect = EPV2_Jobs::next_collect_timestamp();
		$next_publish = $publish_queue_items !== [] ? EPV2_Queue::next_ready_publish_timestamp() : null;
		wp_send_json_success([
			'html' => self::queue_blocks_html($live_queue_items, $review_queue_items, $publish_queue_items, $published_recent_items, $published_archive_items, $orderby, $order, $state_filter, $category_filter, $automation_paused, $next_publish),
			'next_collect' => $next_collect ? (int) $next_collect : 0,
			'next_publish' => $next_publish ? (int) $next_publish : 0,
			'collect_running' => is_array($progress) && (($progress['status'] ?? '') === 'running'),
		]);
	}

	private static function queue_blocks_html(array $live_queue_items, array $review_queue_items, array $publish_queue_items, array $published_recent_items, array $published_archive_items, string $orderby, string $order, string $state_filter, string $category_filter, bool $automation_paused, ?int $next_publish): string {
		ob_start();
		echo '<h2 style="margin-top:18px">Живая очередь</h2>';
		self::render_queue_table($live_queue_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--live', 'live');
		echo '<h2 style="margin-top:24px">Требует ручной проверки</h2>';
		self::render_queue_table($review_queue_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--review', 'review');
		echo '<h2 style="margin-top:24px">Готово к публикации';
		if (! $automation_paused && $next_publish) {
			$publish_remaining = max(0, (int) $next_publish - time());
			$publish_minutes = (int) floor($publish_remaining / MINUTE_IN_SECONDS);
			$publish_seconds = (int) ($publish_remaining % MINUTE_IN_SECONDS);
			echo ' <span style="font-size:13px;font-weight:400;color:#50575e">до публикации: <strong id="epv2-next-publish-countdown" data-target="' . esc_attr((string) ($next_publish * 1000)) . '" data-interval="' . esc_attr((string) (max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS * 1000)) . '">' . esc_html(sprintf('%02d:%02d', $publish_minutes, $publish_seconds)) . '</strong></span>';
		}
		echo '</h2>';
		self::render_queue_table($publish_queue_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--publish', 'publish');
		echo '<h2 style="margin-top:24px">Опубликованные материалы</h2>';
		self::render_queue_table($published_recent_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--published', 'published');
		if ($published_archive_items !== []) {
			echo '<details class="epv2-queue-archive" style="margin-top:16px"><summary style="cursor:pointer;font-weight:600">Архив опубликованных материалов (' . count($published_archive_items) . ')</summary>';
			self::render_queue_table($published_archive_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--archive', 'archive');
			echo '</details>';
		}
		return (string) ob_get_clean();
	}

	private static function render_queue_table(array $items, string $orderby, string $order, string $state_filter, string $category_filter, string $wrap_class = 'epv2-queue-table-wrap', string $table_type = 'default'): void {
		$show_ready_publish_at = $table_type === 'publish';
		echo '<div class="' . esc_attr($wrap_class) . '" style="max-height:420px;overflow:auto;border:1px solid #dcdcde;border-radius:8px;background:#fff">';
		echo '<table class="widefat striped"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll(\'.epv2-queue-check\').forEach(cb => cb.checked = this.checked)"></th><th>' . self::queue_sort_link('id', 'ID', $orderby, $order, $state_filter, $category_filter) . '</th><th>' . self::queue_sort_link('state', 'Статус', $orderby, $order, $state_filter, $category_filter) . '</th><th>' . self::queue_sort_link('priority', 'Приоритет', $orderby, $order, $state_filter, $category_filter) . '</th><th>' . self::queue_sort_link('quality', 'Качество', $orderby, $order, $state_filter, $category_filter) . '</th><th>' . self::queue_sort_link('seo', 'SEO', $orderby, $order, $state_filter, $category_filter) . '</th><th>Метки</th><th>Заголовок</th><th>' . self::queue_sort_link('category', 'Категории', $orderby, $order, $state_filter, $category_filter) . '</th><th>URL</th><th>' . self::queue_sort_link('created_at', 'Создано', $orderby, $order, $state_filter, $category_filter) . '</th>' . ($show_ready_publish_at ? '<th>Готово с</th>' : '') . '<th>' . self::queue_sort_link('published_at', 'Опубликовано', $orderby, $order, $state_filter, $category_filter) . '</th><th>Пост</th><th>Действия</th></tr></thead><tbody>';
		if ($items === []) {
			echo '<tr><td colspan="' . esc_attr((string) ($show_ready_publish_at ? 15 : 14)) . '" style="color:#646970">Нет материалов.</td></tr>';
		}
		foreach ($items as $item) {
				echo '<tr><td><input class="epv2-queue-check" type="checkbox" name="ids[]" value="' . (int) $item->id . '"></td><td>' . (int) $item->id . '</td><td>' . esc_html(self::queue_state_label($item)) . '</td><td>' . self::queue_score_badge($item) . '</td><td>' . self::queue_quality_badge($item) . '</td><td>' . self::queue_seo_badge($item) . '</td><td>' . self::queue_badges($item) . '</td><td>' . esc_html(wp_trim_words((string) $item->original_title, 12, '')) . '</td><td>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
			wp_nonce_field('epv2_update_queue_category_' . (int) $item->id);
			echo '<input type="hidden" name="action" value="epv2_update_queue_category">';
			echo '<input type="hidden" name="id" value="' . (int) $item->id . '">';
			echo self::category_checkboxes('categories', self::normalize_selected_categories((string) ($item->category_final ?: $item->category_proposed)), 'epv2-cat-group-' . (int) $item->id, true, true);
			echo '<script>document.querySelectorAll(".epv2-cat-group-' . (int) $item->id . ' input[type=checkbox]").forEach(function(cb){cb.addEventListener("change",function(){var boxes=[...document.querySelectorAll(".epv2-cat-group-' . (int) $item->id . ' input[type=checkbox]:checked")]; if(boxes.length>3){ this.checked=false; return; } this.form.submit();});});</script>';
			echo '</form>';
			echo '</td><td><a href="' . esc_url($item->original_url) . '" target="_blank" rel="noopener">Открыть</a></td><td>' . esc_html((string) $item->created_at) . '</td>';
			if ($show_ready_publish_at) {
				echo '<td>' . esc_html(self::queue_ready_publish_at($item)) . '</td>';
			}
			echo '<td>' . esc_html(self::queue_published_at($item)) . '</td><td>' . self::queue_post_link($item) . '</td><td>';
			echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=epv2-review&item=' . (int) $item->id)) . '">Проверить</a> ';
			if ($item->state === 'ready_review') {
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_queue_to_publish&id=' . (int) $item->id), 'epv2_queue_to_publish_' . (int) $item->id)) . '">Готово к публикации</a> ';
			}
			if (in_array($item->state, ['ready_publish', 'ready_review', 'draft_created', 'pending_review', 'partially_created'], true)) {
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_publish_now&id=' . (int) $item->id), 'epv2_publish_now_' . (int) $item->id)) . '">Опубликовать</a> ';
			}
			echo '<div style="margin-top:6px;color:#646970;font-size:12px">' . esc_html(self::queue_action_hint((string) $item->state)) . '</div>';
			echo '</td></tr>';
			}
		echo '</tbody></table></div>';
	}

	private static function queue_ready_publish_at(object $item): string {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$ready_at = (string) ($notes['_system']['ready_publish_at'] ?? '');
		if ($ready_at !== '') {
			return $ready_at;
		}
		return (string) ($item->state === 'ready_publish' ? ($item->updated_at ?? '') : '');
	}

	public static function review(): void {
		$item_id = (int) ($_GET['item'] ?? 0);
		$item = $item_id > 0 ? EPV2_Queue::get_item($item_id) : null;
		echo '<div class="wrap"><h1>Проверка материала</h1>';
		if (! $item) {
			echo '<p>Материал не найден.</p></div>';
			return;
		}

		$payload = EPV2_Review::ensure_payload($item);
		$current_style = (string) ($payload['_meta']['style'] ?? EPV2_Settings::get('rewrite_style', 'strict'));
		echo '<p><strong>Оригинал:</strong> <a href="' . esc_url((string) $item->original_url) . '" target="_blank" rel="noopener">открыть источник</a></p>';
			if (! empty($_GET['saved'])) {
				echo '<div class="notice notice-success"><p>Черновой пакет сохранён.</p></div>';
			}
			$notice = get_transient('epv2_review_notice_' . get_current_user_id());
		if ($notice !== false) {
			delete_transient('epv2_review_notice_' . get_current_user_id());
			echo '<div class="notice ' . esc_attr(! empty($notice['success']) ? 'notice-success' : 'notice-error') . '"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p></div>';
			} elseif (! empty($_GET['regenerated'])) {
				echo '<div class="notice notice-success"><p>Поле перегенерировано.</p></div>';
			}
			$quality = is_array($payload['_meta']['quality'] ?? null) ? $payload['_meta']['quality'] : [];
			if ($quality !== []) {
				$quality_score = (int) ($quality['score'] ?? 0);
				$quality_pass = ! empty($quality['pass']);
				echo '<div class="notice ' . esc_attr($quality_pass ? 'notice-info' : 'notice-warning') . '"><p><strong>Редакционное качество:</strong> ' . esc_html((string) $quality_score) . '/100</p>';
				if (! empty($quality['warnings']) && is_array($quality['warnings'])) {
					echo '<ul style="margin:8px 0 0 18px">';
					foreach ($quality['warnings'] as $lang_code => $warnings) {
						if (! is_array($warnings) || $warnings === []) {
							continue;
						}
						echo '<li><strong>' . esc_html(strtoupper((string) $lang_code)) . ':</strong> ' . esc_html(implode('; ', array_map('strval', $warnings))) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
			$seo_quality = is_array($payload['_meta']['seo_quality'] ?? null) ? $payload['_meta']['seo_quality'] : [];
			if ($seo_quality !== []) {
				$seo_score = (int) ($seo_quality['score'] ?? 0);
				$seo_pass = ! empty($seo_quality['pass']);
				echo '<div class="notice ' . esc_attr($seo_pass ? 'notice-info' : 'notice-warning') . '"><p><strong>SEO качество:</strong> ' . esc_html((string) $seo_score) . '/100</p>';
				if (! empty($seo_quality['warnings']) && is_array($seo_quality['warnings'])) {
					echo '<ul style="margin:8px 0 0 18px">';
					foreach ($seo_quality['warnings'] as $lang_code => $warnings) {
						if (! is_array($warnings) || $warnings === []) {
							continue;
						}
						echo '<li><strong>' . esc_html(strtoupper((string) $lang_code)) . ':</strong> ' . esc_html(implode('; ', array_map('strval', $warnings))) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
			$release_quality = is_array($payload['_meta']['release_quality'] ?? null) ? $payload['_meta']['release_quality'] : [];
			if ($release_quality !== []) {
				$release_score = (int) ($release_quality['score'] ?? 0);
				$release_pass = ! empty($release_quality['pass']);
				echo '<div class="notice ' . esc_attr($release_pass ? 'notice-info' : 'notice-warning') . '"><p><strong>Готовность к выпуску:</strong> ' . esc_html((string) $release_score) . '/100</p>';
				if (! empty($release_quality['warnings']) && is_array($release_quality['warnings'])) {
					echo '<ul style="margin:8px 0 0 18px">';
					foreach ($release_quality['warnings'] as $warning) {
						echo '<li>' . esc_html((string) $warning) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
			$google_quality = is_array($payload['_meta']['google_quality'] ?? null) ? $payload['_meta']['google_quality'] : [];
			if ($google_quality !== []) {
				$google_score = (int) ($google_quality['score'] ?? 0);
				$google_pass = ! empty($google_quality['pass']);
				echo '<div class="notice ' . esc_attr($google_pass ? 'notice-info' : 'notice-warning') . '"><p><strong>Google preflight:</strong> ' . esc_html((string) $google_score) . '/100</p>';
				if (! empty($google_quality['warnings']) && is_array($google_quality['warnings'])) {
					echo '<ul style="margin:8px 0 0 18px">';
					foreach ($google_quality['warnings'] as $warning) {
						echo '<li>' . esc_html((string) $warning) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
			$blockers = self::review_blockers($item, $payload);
			if ($blockers !== []) {
				echo '<div class="notice notice-error"><p><strong>Почему материал не прошёл дальше:</strong></p><ul style="margin:8px 0 0 18px">';
				foreach ($blockers as $blocker) {
					echo '<li>' . esc_html($blocker) . '</li>';
				}
				echo '</ul></div>';
			}
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('epv2_save_review_' . $item_id);
		echo '<input type="hidden" name="action" value="epv2_save_review">';
		echo '<input type="hidden" name="id" value="' . $item_id . '">';
		echo '<table class="form-table"><tbody>';
		self::row('Рубрики публикации', self::category_checkboxes('categories', self::normalize_selected_categories((string) ($item->category_final ?: implode(',', $payload['categories'] ?? []) ?: $item->category_proposed)), 'epv2-review-cats', false, true));
		self::row('Стиль рерайта', self::style_select('review_style', $current_style));
		self::row('Редакционные метки', self::editorial_flags($payload));
		self::row('Превью / featured media', '<input id="epv2-global-media-url" name="payload[featured_media_url]" value="' . esc_attr((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? $item->source_image_url)) . '" class="regular-text"> ' . self::media_button('epv2-global-media-url'));
		self::row('Медиа внутри статьи', '<textarea id="epv2-review-inline-media" name="payload[inline_media_urls]" rows="4" class="large-text code" placeholder="Один URL на строку">' . esc_textarea(implode("\n", EPV2_Media::normalize_media_list($payload['inline_media_urls'] ?? []))) . '</textarea><p>' . self::media_button('epv2-review-inline-media', true) . '</p>');
		echo '</tbody></table>';

		foreach (EPV2_Settings::get('publish_languages', ['de', 'uk', 'en']) as $lang) {
			$lang_payload = $payload['languages'][$lang] ?? EPV2_Review::build_language_package($item, $lang);
			echo '<h2>' . esc_html(strtoupper($lang)) . '</h2>';
			echo '<table class="form-table"><tbody>';
			$media_id = 'epv2-media-' . $lang . '-' . $item_id;
			self::row('Заголовок', '<textarea name="payload[languages][' . esc_attr($lang) . '][title]" rows="3" class="large-text">' . esc_textarea((string) ($lang_payload['title'] ?? '')) . '</textarea>' . self::regen_button($item_id, $lang, 'title', $current_style));
			self::row('Лид', '<textarea name="payload[languages][' . esc_attr($lang) . '][excerpt]" rows="4" class="large-text">' . esc_textarea((string) ($lang_payload['excerpt'] ?? '')) . '</textarea>' . self::regen_button($item_id, $lang, 'excerpt', $current_style));
			self::row('Текст', '<textarea name="payload[languages][' . esc_attr($lang) . '][content]" rows="14" class="large-text code">' . esc_textarea((string) ($lang_payload['content'] ?? '')) . '</textarea>' . self::regen_button($item_id, $lang, 'content', $current_style));
			self::row('Медиа', '<input id="' . esc_attr($media_id) . '" name="payload[languages][' . esc_attr($lang) . '][media_url]" value="' . esc_attr((string) ($lang_payload['media_url'] ?? $payload['featured_media_url'] ?? $payload['media_url'] ?? '')) . '" class="regular-text"> ' . self::media_button($media_id) . self::regen_button($item_id, $lang, 'media_url', $current_style));
			self::row('SEO', '<input name="payload[languages][' . esc_attr($lang) . '][seo_title]" value="' . esc_attr((string) ($lang_payload['seo_title'] ?? '')) . '" class="regular-text" placeholder="SEO title"><br><textarea name="payload[languages][' . esc_attr($lang) . '][meta_description]" rows="2" class="large-text" placeholder="Meta description">' . esc_textarea((string) ($lang_payload['meta_description'] ?? '')) . '</textarea><br><input name="payload[languages][' . esc_attr($lang) . '][slug]" value="' . esc_attr((string) ($lang_payload['slug'] ?? '')) . '" class="regular-text" placeholder="slug"><br><textarea name="payload[languages][' . esc_attr($lang) . '][focus_keywords]" rows="2" class="large-text" placeholder="Один ключ на строку">' . esc_textarea(implode("\n", (array) ($lang_payload['focus_keywords'] ?? []))) . '</textarea>');
			echo '</tbody></table>';
		}

		submit_button('Сохранить черновой пакет');
		echo ' <a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_review_ready_publish&id=' . $item_id), 'epv2_review_ready_publish_' . $item_id)) . '">Сохранить и подготовить к публикации</a>';
		if (in_array((string) $item->state, ['ready_review', 'ready_publish', 'draft_created', 'pending_review', 'partially_created'], true)) {
			echo ' <a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_publish_now&id=' . $item_id), 'epv2_publish_now_' . $item_id)) . '">Опубликовать</a>';
		}
		echo '</form>';
		echo '<h2>Предпросмотр</h2>';
		if (! empty($payload['featured_media_url']) || ! empty($payload['inline_media_urls'])) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:16px 0">';
			echo '<h3 style="margin-top:0">Медиа статьи</h3>';
			if (! empty($payload['featured_media_url'])) {
				echo '<p><strong>Превью:</strong></p>' . self::media_preview((string) $payload['featured_media_url']);
			}
			if (! empty($payload['inline_media_urls'])) {
				echo '<p><strong>Внутри статьи:</strong></p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">';
				foreach (EPV2_Media::normalize_media_list($payload['inline_media_urls']) as $inline_url) {
					echo '<div>' . self::media_preview((string) $inline_url) . '</div>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		foreach (EPV2_Settings::get('publish_languages', ['de', 'uk', 'en']) as $lang) {
			$lang_payload = $payload['languages'][$lang] ?? [];
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:16px 0">';
			echo '<h3 style="margin-top:0">' . esc_html(strtoupper($lang)) . '</h3>';
			if (! empty($lang_payload['media_url'])) {
				$media_url = (string) $lang_payload['media_url'];
				$type = EPV2_Media::detect_type($media_url);
				if ($type === 'image') {
					echo '<p><img src="' . esc_url($media_url) . '" alt="" style="max-width:320px;height:auto;border-radius:6px"></p>';
				} elseif ($type === 'video') {
					echo '<p><video controls preload="metadata" style="max-width:320px;height:auto;border-radius:6px"><source src="' . esc_url($media_url) . '"></video></p>';
				} else {
					echo '<p><a href="' . esc_url($media_url) . '" target="_blank" rel="noopener">Видео / embed</a></p>';
				}
			}
			echo '<p><strong>' . esc_html((string) ($lang_payload['title'] ?? '')) . '</strong></p>';
			echo '<p>' . esc_html((string) ($lang_payload['excerpt'] ?? '')) . '</p>';
			if (! empty($lang_payload['seo_title']) || ! empty($lang_payload['meta_description']) || ! empty($lang_payload['slug'])) {
				echo '<div style="font-size:12px;color:#50575e;margin:10px 0;padding:10px;background:#f6f7f7;border-radius:6px">';
				if (! empty($lang_payload['seo_title'])) {
					echo '<p style="margin:0 0 6px"><strong>SEO title:</strong> ' . esc_html((string) $lang_payload['seo_title']) . '</p>';
				}
				if (! empty($lang_payload['meta_description'])) {
					echo '<p style="margin:0 0 6px"><strong>Meta description:</strong> ' . esc_html((string) $lang_payload['meta_description']) . '</p>';
				}
				if (! empty($lang_payload['slug'])) {
					echo '<p style="margin:0 0 6px"><strong>Slug:</strong> ' . esc_html((string) $lang_payload['slug']) . '</p>';
				}
				if (! empty($lang_payload['focus_keywords'])) {
					echo '<p style="margin:0"><strong>Ключи:</strong> ' . esc_html(implode(', ', array_map('strval', (array) $lang_payload['focus_keywords']))) . '</p>';
				}
				echo '</div>';
			}
			echo '<div style="max-width:900px">' . wp_kses_post(wpautop((string) ($lang_payload['content'] ?? ''))) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	public static function settings(): void {
		$settings = EPV2_Settings::get_all();
		$ai_usage = EPV2_Stats::ai_usage_today();
		echo '<div class="wrap"><h1>Настройки</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('epv2_save_settings');
		echo '<input type="hidden" name="action" value="epv2_save_settings">';
		echo '<table class="form-table"><tbody>';
		self::row('Режим', '<select name="mode"><option value="manual"' . selected($settings['mode'], 'manual', false) . '>ручной</option><option value="semi"' . selected($settings['mode'], 'semi', false) . '>полуавто</option><option value="auto"' . selected($settings['mode'], 'auto', false) . '>авто</option></select>');
		self::row('Статус публикации по умолчанию', '<select name="default_post_status"><option value="draft"' . selected($settings['default_post_status'], 'draft', false) . '>черновик</option><option value="pending"' . selected($settings['default_post_status'], 'pending', false) . '>на проверке</option><option value="publish"' . selected($settings['default_post_status'], 'publish', false) . '>публиковать</option></select>');
		self::row('AI провайдер', self::provider_select('ai_provider', (string) $settings['ai_provider'], 'epv2-ai-provider'));
		self::row('AI модель', self::model_select('ai_model', (string) $settings['ai_provider'], (string) $settings['ai_model'], 'epv2-ai-model') . self::usage_hint((string) $settings['ai_provider'], (string) $settings['ai_model'], $ai_usage, 'epv2-ai-usage-primary'));
		self::row('Резервный провайдер', self::provider_select('ai_fallback_provider', (string) $settings['ai_fallback_provider'], 'epv2-ai-fallback-provider'));
		self::row('Резервная модель', self::model_select('ai_fallback_model', (string) $settings['ai_fallback_provider'], (string) $settings['ai_fallback_model'], 'epv2-ai-fallback-model') . self::usage_hint((string) $settings['ai_fallback_provider'], (string) $settings['ai_fallback_model'], $ai_usage, 'epv2-ai-usage-fallback'));
		self::row('Стиль рерайта по умолчанию', self::style_select('rewrite_style', (string) $settings['rewrite_style']));
		self::row('Gemini API ключ', '<input name="ai_keys[gemini]" value="' . esc_attr($settings['ai_keys']['gemini']) . '" class="regular-text">');
		self::row('Gemini: web grounding', '<label><input type="checkbox" name="gemini_search_grounding_enabled"' . checked(! empty($settings['gemini_search_grounding_enabled']), true, false) . '> использовать Google Search для черновиков и тестов</label>');
		self::row('Gemini: URL context', '<label><input type="checkbox" name="gemini_url_context_enabled"' . checked(! empty($settings['gemini_url_context_enabled']), true, false) . '> учитывать URL первоисточника как контекст</label>');
		self::row('Gemini: URL в промте', '<label><input type="checkbox" name="gemini_use_source_url_in_prompt"' . checked(! empty($settings['gemini_use_source_url_in_prompt']), true, false) . '> явно передавать URL источника в prompt</label>');
		self::row('Gemini: требовать ссылки', '<label><input type="checkbox" name="gemini_require_citations"' . checked(! empty($settings['gemini_require_citations']), true, false) . '> просить модель вернуть цитаты/ссылочные опоры в raw-ответе</label>');
		self::row('DeepSeek API ключ', '<input name="ai_keys[deepseek]" value="' . esc_attr($settings['ai_keys']['deepseek']) . '" class="regular-text">');
		self::row('OpenAI API ключ', '<input name="ai_keys[openai]" value="' . esc_attr($settings['ai_keys']['openai']) . '" class="regular-text">');
		self::row('Anthropic API ключ', '<input name="ai_keys[anthropic]" value="' . esc_attr($settings['ai_keys']['anthropic']) . '" class="regular-text">');
		self::row('Pexels API ключ', '<input name="image_keys[pexels]" value="' . esc_attr($settings['image_keys']['pexels']) . '" class="regular-text">');
		self::row('Интервал сбора (мин)', '<input name="collect_interval_minutes" type="number" value="' . (int) $settings['collect_interval_minutes'] . '" class="small-text">');
		self::row('Интервал обработки (мин)', '<input name="process_interval_minutes" type="number" value="' . (int) $settings['process_interval_minutes'] . '" class="small-text"> <span class="description">Рекомендуется 5 минут как backstop. Основная обработка идёт сразу по очереди.</span>');
		self::row('Интервал публикации (мин)', '<input name="publish_interval_minutes" type="number" value="' . (int) $settings['publish_interval_minutes'] . '" class="small-text"> <span class="description">Рекомендуется 5 минут.</span>');
		self::row('Лимит на рубрику за один сбор', '<input name="max_collect_per_category" type="number" value="' . (int) $settings['max_collect_per_category'] . '" class="small-text">');
		self::row('Режим экономии AI', '<select name="ai_budget_mode"><option value="normal"' . selected($settings['ai_budget_mode'], 'normal', false) . '>Нормальный</option><option value="economy"' . selected($settings['ai_budget_mode'], 'economy', false) . '>Экономия</option><option value="critical"' . selected($settings['ai_budget_mode'], 'critical', false) . '>Критичный</option></select>');
		self::row('Жёсткость отбора', '<select name="ai_selection_strictness"><option value="low"' . selected($settings['ai_selection_strictness'], 'low', false) . '>Низкая</option><option value="medium"' . selected($settings['ai_selection_strictness'], 'medium', false) . '>Средняя</option><option value="high"' . selected($settings['ai_selection_strictness'], 'high', false) . '>Высокая</option></select>');
		self::row('Мягкий лимит AI-запросов в день', '<input name="ai_daily_request_soft_limit" type="number" value="' . (int) $settings['ai_daily_request_soft_limit'] . '" class="small-text">');
		self::row('Мягкий лимит AI-токенов в день', '<input name="ai_daily_token_soft_limit" type="number" value="' . (int) $settings['ai_daily_token_soft_limit'] . '" class="small-text">');
		self::row('Целевой объём публикаций в день', '<input name="daily_publish_target" type="number" value="' . (int) $settings['daily_publish_target'] . '" class="small-text">');
		self::row('Соблюдать дневной лимит публикаций', '<label><input type="checkbox" name="enforce_daily_publish_target"' . checked(! empty($settings['enforce_daily_publish_target']), true, false) . '> ограничивать выпуск материалов по целевому суточному плану</label>');
		self::row('Внешний worker', '<select name="worker_mode"><option value="disabled"' . selected((string) ($settings['worker_mode'] ?? 'disabled'), 'disabled', false) . '>выключен</option><option value="cli"' . selected((string) ($settings['worker_mode'] ?? 'disabled'), 'cli', false) . '>CLI worker</option></select><p class="description">Пока это подготовка контура вынесения тяжёлой обработки из WordPress. По умолчанию отключено.</p>');
		self::row('Worker: Python', '<input name="worker_python_bin" value="' . esc_attr((string) ($settings['worker_python_bin'] ?? 'python3')) . '" class="regular-text">');
		self::row('Worker: команда', '<input name="worker_cli_command" value="' . esc_attr((string) ($settings['worker_cli_command'] ?? 'python3 -m epv2_worker')) . '" class="regular-text code">');
		self::row('Worker: PYTHONPATH', '<input name="worker_src_dir" value="' . esc_attr((string) ($settings['worker_src_dir'] ?? '/root/projects/europulse/worker/src')) . '" class="regular-text code">');
		self::row('Worker: timeout (сек)', '<input name="worker_timeout_seconds" type="number" value="' . (int) ($settings['worker_timeout_seconds'] ?? 180) . '" class="small-text">');
		self::row('Хранить завершённые элементы очереди (дней)', '<input name="queue_retention_days" type="number" value="' . (int) $settings['queue_retention_days'] . '" class="small-text">');
		self::row('TTL для новых элементов очереди (часы)', '<input name="queue_new_ttl_hours" type="number" value="' . (int) $settings['queue_new_ttl_hours'] . '" class="small-text">');
		self::row('Максимум новых элементов на рубрику', '<input name="queue_new_max_per_category" type="number" value="' . (int) $settings['queue_new_max_per_category'] . '" class="small-text">');
		self::row('Максимум новых элементов на источник', '<input name="queue_new_max_per_source" type="number" value="' . (int) $settings['queue_new_max_per_source'] . '" class="small-text">');
		self::row('Промт для авто-режима', '<textarea name="prompts[auto_rewrite]" rows="8" class="large-text code">' . esc_textarea((string) ($settings['prompts']['auto_rewrite'] ?? '')) . '</textarea><p class="description">Используется для автоматического сбора и автогенерации.</p>');
		self::row('Промт для аналитики', '<textarea name="prompts[analysis_rewrite]" rows="8" class="large-text code">' . esc_textarea((string) ($settings['prompts']['analysis_rewrite'] ?? '')) . '</textarea><p class="description">Используется для weekly analysis и крупных аналитических материалов по теме.</p>');
		self::row('Промт для ручного режима', '<textarea name="prompts[manual_rewrite]" rows="12" class="large-text code">' . esc_textarea((string) ($settings['prompts']['manual_rewrite'] ?? $settings['prompts']['news_default'] ?? '')) . '</textarea><p class="description">Используется в ручной сборке, перегенерации полей и редакторской доработке. Это и есть основной большой редакторский prompt.</p>');
		echo '</tbody></table>';
		submit_button('Сохранить настройки');
		echo '</form></div>';
	}

	public static function manual(): void {
		$draft = EPV2_Manual_Mode::get_draft();
		$notice = get_transient('epv2_manual_notice_' . get_current_user_id());
		if ($notice !== false) {
			delete_transient('epv2_manual_notice_' . get_current_user_id());
		}
		echo '<div class="wrap"><h1>Ручной режим</h1>';
		echo '<p>Можно написать материал вручную, улучшить отдельные поля через AI, прикрепить несколько медиа и затем собрать статью в редакционную проверку.</p>';
		if (is_array($notice) && ! empty($notice['message'])) {
			echo '<div class="notice ' . esc_attr(! empty($notice['success']) ? 'notice-success' : 'notice-error') . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
		}
		if (! empty($_GET['item'])) {
			echo '<div class="notice notice-success"><p>Материал собран. <a href="' . esc_url(admin_url('admin.php?page=epv2-review&item=' . (int) $_GET['item'])) . '">Открыть проверку</a></p></div>';
		}
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('epv2_manual_submit');
		echo '<input type="hidden" name="action" value="epv2_manual_submit">';
		echo '<table class="form-table"><tbody>';
		self::row('URL / первоисточник', '<input name="draft[source_url]" value="' . esc_attr((string) $draft['source_url']) . '" class="regular-text"> <button class="button" name="manual_op" value="import_url">Импортировать по URL</button>');
		self::row('Рубрики', self::category_checkboxes('draft[categories]', self::normalize_selected_categories($draft['categories']), 'epv2-manual-cats'));
		self::row('Стиль', self::style_select('draft[style]', (string) $draft['style']));
		self::row('Редакционные метки', self::editorial_flags(['_meta' => $draft['editorial']]));
		self::row('Заголовок', '<textarea name="draft[title]" rows="3" class="large-text">' . esc_textarea((string) $draft['title']) . '</textarea><p><button class="button" name="manual_op" value="rewrite_title">Сделать рерайт AI</button></p>');
		self::row('Лид / dek', '<textarea name="draft[excerpt]" rows="4" class="large-text">' . esc_textarea((string) $draft['excerpt']) . '</textarea><p><button class="button" name="manual_op" value="rewrite_excerpt">Перегенерировать лид AI</button></p>');
		self::row('Текст', '<textarea name="draft[content]" rows="16" class="large-text code">' . esc_textarea((string) $draft['content']) . '</textarea><p><button class="button" name="manual_op" value="rewrite_content">Перегенерировать текст AI</button></p>');
		self::row('Превью / featured media', '<input id="epv2-manual-featured-media" name="draft[featured_media_url]" value="' . esc_attr((string) $draft['featured_media_url']) . '" class="regular-text"> ' . self::media_button('epv2-manual-featured-media'));
		self::row('Медиа внутри статьи', '<textarea id="epv2-manual-inline-media" name="draft[inline_media_urls]" rows="5" class="large-text code" placeholder="Один URL на строку">' . esc_textarea(implode("\n", EPV2_Media::normalize_media_list($draft['inline_media_urls']))) . '</textarea><p>' . self::media_button('epv2-manual-inline-media', true) . ' <span class="description">Можно добавлять несколько изображений и видео. Featured media пойдёт в превью статьи, остальные — внутрь текста.</span></p>');
		echo '</tbody></table>';
		echo '<h2>SEO</h2><table class="form-table"><tbody>';
		self::row('SEO title', '<input name="draft[seo][seo_title]" value="' . esc_attr((string) ($draft['seo']['seo_title'] ?? '')) . '" class="regular-text">');
		self::row('Meta description', '<textarea name="draft[seo][meta_description]" rows="3" class="large-text">' . esc_textarea((string) ($draft['seo']['meta_description'] ?? '')) . '</textarea>');
		self::row('Slug', '<input name="draft[seo][slug]" value="' . esc_attr((string) ($draft['seo']['slug'] ?? '')) . '" class="regular-text">');
		self::row('SEO-ключи', '<textarea name="draft[seo][focus_keywords]" rows="3" class="large-text" placeholder="Один ключ на строку">' . esc_textarea(implode("\n", (array) ($draft['seo']['focus_keywords'] ?? []))) . '</textarea><p><button class="button" name="manual_op" value="seo_optimize">Сгенерировать и SEO-оптимизировать AI</button></p>');
		echo '</tbody></table>';
		echo '<p class="submit">';
		echo '<button class="button button-secondary" name="manual_op" value="save">Сохранить ручной черновик</button> ';
		echo '<button class="button button-secondary" name="manual_op" value="reset" onclick="return confirm(\'Очистить ручной черновик?\')">Очистить</button> ';
		echo '<button class="button button-primary" name="manual_op" value="to_review">Собрать материал для проверки</button>';
		echo '</p></form>';
		if ($draft['featured_media_url'] !== '' || ! empty($draft['inline_media_urls'])) {
			echo '<h2>Предпросмотр медиа</h2><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:1100px">';
			if ($draft['featured_media_url'] !== '') {
				echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px"><strong>Превью</strong>' . self::media_preview((string) $draft['featured_media_url']) . '</div>';
			}
			foreach (EPV2_Media::normalize_media_list($draft['inline_media_urls']) as $index => $url) {
				echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px"><strong>Внутри статьи #' . (int) ($index + 1) . '</strong>' . self::media_preview((string) $url) . '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	public static function logs(): void {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}epv2_log ORDER BY created_at DESC LIMIT 100");
		echo '<div class="wrap"><h1>Логи</h1><table class="widefat striped"><thead><tr><th>Время</th><th>Уровень</th><th>Модуль</th><th>Сообщение</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			echo '<tr><td>' . esc_html((string) $row->created_at) . '</td><td>' . esc_html((string) $row->level) . '</td><td>' . esc_html((string) $row->module) . '</td><td>' . esc_html((string) $row->message) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function runs(): void {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}epv2_runs ORDER BY started_at DESC LIMIT 100");
		echo '<div class="wrap"><h1>Запуски</h1><table class="widefat striped"><thead><tr><th>Старт</th><th>Задача</th><th>Статус</th><th>Элементы</th><th>Ошибки</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			echo '<tr><td>' . esc_html((string) $row->started_at) . '</td><td>' . esc_html((string) $row->job_name) . '</td><td>' . esc_html((string) $row->status) . '</td><td>' . (int) $row->item_count . '</td><td>' . (int) $row->error_count . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function save_settings(): void {
		check_admin_referer('epv2_save_settings');
		if (! current_user_can('manage_europulse_autopilot')) {
			wp_die('Недостаточно прав.');
		}
		EPV2_Settings::set_all($_POST);
		EPV2_Jobs::clear_scheduled();
		EPV2_Jobs::schedule_recurring();
		wp_safe_redirect(admin_url('admin.php?page=epv2-settings&updated=1'));
		exit;
	}

	public static function run_collect(): void {
		check_admin_referer('epv2_run_collect');
		EPV2_Collector::run_scheduled(true);
		if (! EPV2_Jobs::automation_paused()) {
			for ($i = 0; $i < 3; $i++) {
				EPV2_AI_Processor::process_scheduled(true, true);
			}
			if ((string) EPV2_Settings::get('mode', 'semi') === 'auto') {
				for ($i = 0; $i < 3; $i++) {
					EPV2_Publisher::publish_scheduled(true);
				}
			}
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&ran=collect'));
		exit;
	}

	public static function run_process(): void {
		check_admin_referer('epv2_run_process');
		EPV2_AI_Processor::process_scheduled(true, true);
		if (! EPV2_Jobs::automation_paused() && (string) EPV2_Settings::get('mode', 'semi') === 'auto') {
			EPV2_Publisher::publish_scheduled(true);
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&ran=process'));
		exit;
	}

	public static function run_publish(): void {
		check_admin_referer('epv2_run_publish');
		EPV2_Publisher::publish_scheduled(true);
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&ran=publish'));
		exit;
	}

	public static function pause_automation(): void {
		check_admin_referer('epv2_pause_automation');
		EPV2_Jobs::pause_automation();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&automation=paused'));
		exit;
	}

	public static function resume_automation(): void {
		check_admin_referer('epv2_resume_automation');
		EPV2_Jobs::resume_automation();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&automation=running'));
		exit;
	}

	public static function reset_stats(): void {
		check_admin_referer('epv2_reset_stats');
		EPV2_Stats::reset_today();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&stats_reset=1'));
		exit;
	}

	public static function prune_queue(): void {
		check_admin_referer('epv2_prune_queue');
		EPV2_Queue::prune_stale((int) EPV2_Settings::get('queue_retention_days', 3));
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue&pruned=1'));
		exit;
	}

	public static function queue_to_publish(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_queue_to_publish_' . $id);
		if ($id > 0) {
			$item = EPV2_Queue::get_item($id);
			if ($item) {
				$payload = EPV2_Review::ensure_payload($item);
				if (EPV2_AI_Processor::payload_is_publish_ready($payload)) {
					EPV2_Review::save_payload($id, $payload);
					EPV2_Queue::mark_state($id, 'ready_publish');
				} else {
					wp_safe_redirect(admin_url('admin.php?page=epv2-queue&queue_notice=not_publish_ready'));
					exit;
				}
			}
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	public static function update_queue_category(): void {
		$id = (int) ($_POST['id'] ?? 0);
		check_admin_referer('epv2_update_queue_category_' . $id);
		if ($id > 0) {
			$item = EPV2_Queue::get_item($id);
			$categories = self::normalize_selected_categories($_POST['categories'] ?? []);
			if ($item) {
				$payload = EPV2_Review::decode_payload((string) ($item->ai_payload ?? ''));
				if ($payload !== []) {
					$payload['categories'] = $categories;
					EPV2_Review::save_payload($id, $payload);
				} else {
					EPV2_Queue::update_fields($id, ['category_final' => implode(',', $categories)]);
				}
				if (in_array((string) ($item->state ?? ''), ['published', 'partially_created'], true)) {
					EPV2_Publisher::synchronize_published_bundle_taxonomy($id, $categories);
				}
			}
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	public static function publish_now(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_publish_now_' . $id);
		$item = EPV2_Queue::get_item($id);
		if ($item) {
			$payload = EPV2_Review::ensure_payload($item);
			if (! EPV2_AI_Processor::payload_is_publish_ready($payload)) {
				wp_safe_redirect(admin_url('admin.php?page=epv2-queue&queue_notice=publish_blocked'));
				exit;
			}
			EPV2_Publisher::publish_item($item, 'publish');
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	public static function review_ready_publish(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_review_ready_publish_' . $id);
		if ($id > 0) {
			$item = EPV2_Queue::get_item($id);
			if ($item) {
				$payload = EPV2_Review::ensure_payload($item);
				if (EPV2_AI_Processor::payload_is_publish_ready($payload)) {
					EPV2_Review::save_payload($id, $payload);
					EPV2_Queue::mark_state($id, 'ready_publish');
				} else {
					wp_safe_redirect(admin_url('admin.php?page=epv2-review&item=' . $id . '&queue_notice=not_publish_ready'));
					exit;
				}
			}
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-review&item=' . $id . '&saved=1'));
		exit;
	}

	public static function manual_generate(): void {
		check_admin_referer('epv2_manual_generate');
		$result = EPV2_Manual_Mode::queue_from_url(esc_url_raw($_POST['url'] ?? ''));
		set_transient('epv2_manual_result_' . get_current_user_id(), $result['data'] ?? ['message' => $result['message'] ?? 'empty'], 5 * MINUTE_IN_SECONDS);
		if (! empty($result['success']) && ! empty($result['item_id'])) {
			wp_safe_redirect(admin_url('admin.php?page=epv2-manual&queued=1&item=' . (int) $result['item_id']));
			exit;
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-manual&epv2_manual_result=1'));
		exit;
	}

	public static function manual_submit(): void {
		check_admin_referer('epv2_manual_submit');
		$draft_input = is_array($_POST['draft'] ?? null) ? $_POST['draft'] : [];
		$draft_input['editorial'] = is_array($_POST['editorial'] ?? null) ? $_POST['editorial'] : [];
		$draft = EPV2_Manual_Mode::normalize_draft($draft_input);
		$op = sanitize_text_field($_POST['manual_op'] ?? 'save');

		try {
			switch ($op) {
				case 'import_url':
					EPV2_Manual_Mode::import_from_url((string) ($draft['source_url'] ?? ''), $draft);
					self::set_manual_notice('Материал считан по URL и заполнен в ручный черновик.');
					break;
				case 'rewrite_title':
					EPV2_Manual_Mode::rewrite_field($draft, 'title', (string) $draft['style']);
					self::set_manual_notice('Заголовок перегенерирован.');
					break;
				case 'rewrite_excerpt':
					EPV2_Manual_Mode::rewrite_field($draft, 'excerpt', (string) $draft['style']);
					self::set_manual_notice('Лид перегенерирован.');
					break;
				case 'rewrite_content':
					EPV2_Manual_Mode::rewrite_field($draft, 'content', (string) $draft['style']);
					self::set_manual_notice('Текст перегенерирован.');
					break;
				case 'seo_optimize':
					EPV2_Manual_Mode::optimize_seo($draft);
					self::set_manual_notice('SEO-блок обновлён.');
					break;
				case 'reset':
					EPV2_Manual_Mode::reset_draft();
					self::set_manual_notice('Ручной черновик очищен.');
					wp_safe_redirect(admin_url('admin.php?page=epv2-manual'));
					exit;
				case 'to_review':
					$item_id = EPV2_Manual_Mode::create_review_item($draft);
					self::set_manual_notice('Материал собран и отправлен в редакционную проверку.');
					wp_safe_redirect(admin_url('admin.php?page=epv2-manual&item=' . $item_id));
					exit;
				default:
					EPV2_Manual_Mode::save_draft($draft);
					self::set_manual_notice('Ручной черновик сохранён.');
					break;
			}
		} catch (Throwable $e) {
			EPV2_Manual_Mode::save_draft($draft);
			self::set_manual_notice($e->getMessage(), false);
		}

		wp_safe_redirect(admin_url('admin.php?page=epv2-manual'));
		exit;
	}

	public static function test_source(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_test_source_' . $id);
		$source = $id > 0 ? EPV2_Sources::get($id) : null;
		if (! $source) {
			wp_safe_redirect(admin_url('admin.php?page=epv2-sources'));
			exit;
		}
		$result = EPV2_Source_Tester::test((array) $source);
		set_transient('epv2_source_test_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources&tested=' . $id));
		exit;
	}

	public static function save_review(): void {
		$id = (int) ($_POST['id'] ?? 0);
		check_admin_referer('epv2_save_review_' . $id);
		$item = $id > 0 ? EPV2_Queue::get_item($id) : null;
		if (! $item) {
			wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
			exit;
		}

		$payload = EPV2_Review::ensure_payload($item);
		$posted = $_POST['payload'] ?? [];
		$payload['featured_media_url'] = esc_url_raw($posted['featured_media_url'] ?? ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
		$payload['media_url'] = $payload['featured_media_url'];
		$payload['inline_media_urls'] = EPV2_Media::normalize_media_list($posted['inline_media_urls'] ?? ($payload['inline_media_urls'] ?? []));
		$payload['_meta']['style'] = in_array(($_POST['review_style'] ?? ''), ['strict', 'analytic', 'lively'], true) ? sanitize_text_field($_POST['review_style']) : (string) EPV2_Settings::get('rewrite_style', 'strict');
		$payload['_meta']['breaking'] = ! empty($_POST['editorial']['breaking']);
		$payload['_meta']['top_story'] = ! empty($_POST['editorial']['top_story']);
		$payload['_meta']['breaking_hours'] = max(1, min(24, (int) ($_POST['editorial']['breaking_hours'] ?? EPV2_Settings::get('auto_breaking_hours', 6))));
		$payload['seo'] = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
		foreach (EPV2_Settings::get('publish_languages', ['de', 'uk', 'en']) as $lang) {
			$payload['languages'][$lang] = [
				'title' => sanitize_text_field($posted['languages'][$lang]['title'] ?? ''),
				'excerpt' => sanitize_textarea_field($posted['languages'][$lang]['excerpt'] ?? ''),
				'content' => wp_kses_post($posted['languages'][$lang]['content'] ?? ''),
				'media_url' => esc_url_raw($posted['languages'][$lang]['media_url'] ?? ($payload['featured_media_url'] ?? '')),
				'seo_title' => sanitize_text_field($posted['languages'][$lang]['seo_title'] ?? ''),
				'meta_description' => sanitize_textarea_field($posted['languages'][$lang]['meta_description'] ?? ''),
				'slug' => sanitize_title($posted['languages'][$lang]['slug'] ?? ''),
				'focus_keywords' => array_values(array_filter(array_map('sanitize_text_field', preg_split('/[\r\n,]+/u', (string) ($posted['languages'][$lang]['focus_keywords'] ?? '')) ?: []))),
				'lang' => $lang,
			];
		}
		$primary_lang = in_array('de', EPV2_Settings::get('publish_languages', ['de', 'uk', 'en']), true) ? 'de' : EPV2_Settings::get('publish_languages', ['de', 'uk', 'en'])[0];
		$payload['seo'] = [
			'seo_title' => (string) ($payload['languages'][$primary_lang]['seo_title'] ?? ''),
			'meta_description' => (string) ($payload['languages'][$primary_lang]['meta_description'] ?? ''),
			'slug' => (string) ($payload['languages'][$primary_lang]['slug'] ?? ''),
			'focus_keywords' => (array) ($payload['languages'][$primary_lang]['focus_keywords'] ?? []),
		];

		$categories = self::normalize_selected_categories($_POST['categories'] ?? ($item->category_final ?: $item->category_proposed));
		$payload['categories'] = $categories;
		EPV2_Review::save_payload($id, $payload);
		EPV2_Queue::update_fields($id, ['category_final' => implode(',', $categories)]);
		if (in_array((string) ($item->state ?? ''), ['published', 'partially_created'], true)) {
			EPV2_Publisher::synchronize_published_bundle_from_payload($id, $payload);
		} else {
			EPV2_Publisher::synchronize_published_bundle_taxonomy($id, $categories);
		}

		wp_safe_redirect(admin_url('admin.php?page=epv2-review&item=' . $id . '&saved=1'));
		exit;
	}

	public static function regenerate_field(): void {
		$id = (int) ($_GET['id'] ?? 0);
		$lang = sanitize_text_field($_GET['lang'] ?? 'de');
		$field = sanitize_text_field($_GET['field'] ?? 'title');
		$style = in_array(($_GET['style'] ?? ''), ['strict', 'analytic', 'lively'], true) ? sanitize_text_field($_GET['style']) : (string) EPV2_Settings::get('rewrite_style', 'strict');
		check_admin_referer('epv2_regenerate_field_' . $id . '_' . $lang . '_' . $field);
		$item = $id > 0 ? EPV2_Queue::get_item($id) : null;
		if ($item) {
			try {
				$payload = EPV2_AI_Processor::regenerate_for_field($item, $lang, $field, $style);
				EPV2_Review::save_payload($id, $payload);
				set_transient('epv2_review_notice_' . get_current_user_id(), ['success' => true, 'message' => 'Поле перегенерировано.'], MINUTE_IN_SECONDS * 5);
			} catch (Throwable $e) {
				set_transient('epv2_review_notice_' . get_current_user_id(), ['success' => false, 'message' => 'Не удалось перегенерировать поле: ' . $e->getMessage()], MINUTE_IN_SECONDS * 5);
			}
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-review&item=' . $id . '&regenerated=1'));
		exit;
	}

	public static function save_source(): void {
		check_admin_referer('epv2_save_source');
		if (! current_user_can('edit_europulse_autopilot_sources')) {
			wp_die('Недостаточно прав.');
		}
		EPV2_Sources::save($_POST);
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources&updated=1'));
		exit;
	}

	public static function toggle_source(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_toggle_source_' . $id);
		if ($id > 0) {
			EPV2_Sources::toggle($id);
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources'));
		exit;
	}

	public static function delete_source(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_delete_source_' . $id);
		if ($id > 0) {
			EPV2_Sources::delete($id);
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources'));
		exit;
	}

	public static function import_recommended_sources(): void {
		check_admin_referer('epv2_import_recommended_sources');
		EPV2_Source_Library::import();
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources&imported=1'));
		exit;
	}

	public static function delete_queue_items(): void {
		check_admin_referer('epv2_delete_queue_items');
		$ids = $_POST['ids'] ?? [];
		if (empty($ids) && ! empty($_POST['ids_csv'])) {
			$ids = explode(',', sanitize_text_field((string) $_POST['ids_csv']));
		}
		EPV2_Queue::delete_items($ids);
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	public static function clear_queue(): void {
		check_admin_referer('epv2_clear_queue');
		EPV2_Queue::clear_all();
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	private static function row(string $label, string $field): void {
		echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . '</td></tr>';
	}

	private static function provider_select(string $name, string $selected, string $id = ''): string {
		$options = EPV2_Settings::ai_provider_options();
		$html = '<select name="' . esc_attr($name) . '"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>';
		foreach ($options as $value => $label) {
			$html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	private static function model_select(string $name, string $provider, string $selected, string $id = ''): string {
		$all = EPV2_Settings::ai_model_options();
		$options = $all[$provider] ?? [];
		if (! isset($options[$selected]) && ! empty($options)) {
			$selected = (string) array_key_first($options);
		}
		$html = '<select name="' . esc_attr($name) . '"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>';
		foreach ($options as $value => $label) {
			$html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	private static function metric_label(string $key): string {
		$labels = [
			'metric_date' => 'Дата',
			'collected' => 'Собрано',
			'rewritten' => 'Обработано',
			'published' => 'Опубликовано',
			'duplicates' => 'Дубликаты',
			'errors' => 'Ошибки',
			'ai_tokens' => 'AI токены',
		];
		return $labels[$key] ?? $key;
	}

	private static function latest_human_issues(): array {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT module, message, context FROM {$wpdb->prefix}epv2_log WHERE level IN ('warning','error') ORDER BY created_at DESC LIMIT 8");
		$issues = [];
		foreach ($rows as $row) {
			$issues[] = [
				'module' => (string) $row->module,
				'message' => self::humanize_issue((string) $row->message, (string) $row->context),
			];
		}
		return $issues;
	}

	private static function humanize_issue(string $message, string $context_json = ''): string {
		$context = json_decode($context_json, true);
		$text = $message;
		$error = is_array($context) ? (string) ($context['error'] ?? '') : '';
		$combined = trim($text . ' ' . $error);
		if (str_contains($combined, '429')) {
			return 'Провайдер AI упёрся в лимит запросов или квоту. Материал не смог пройти нормальную генерацию.';
		}
		if (str_contains($combined, '401')) {
			return 'Провайдер AI отклоняет ключ или доступ. Нужно проверить API key, аккаунт и правильность платформы API.';
		}
		if (str_contains($combined, 'Empty HTML response')) {
			return 'Источник вернул пустую страницу. Возможно, временный сбой сайта или защита от парсинга.';
		}
		if (str_contains($combined, 'AI provider returned empty content')) {
			return 'AI ответил пусто. Материал не был полноценно сгенерирован.';
		}
		if (str_contains($combined, 'Source failed')) {
			return 'Один из источников не удалось обработать. Деталь ошибки сохранена в логах источников.';
		}
		return $combined !== '' ? $combined : 'Неизвестная ошибка. Посмотри логи плагина.';
	}

	private static function source_status_label(bool $is_active): string {
		return $is_active ? 'Активен' : 'Пауза';
	}

	private static function queue_state_label(object $item): string {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$live_status = trim((string) ($notes['_system']['live_status'] ?? ''));
		$retry_after = trim((string) ($notes['_system']['retry_after'] ?? ''));
		$state = (string) $item->state;
		if (in_array($state, ['published', 'draft_created', 'pending_review', 'partially_created'], true)) {
			$statuses = self::post_statuses_for_queue($item);
			if (empty($statuses)) {
				return 'Ошибка публикации';
			}
			if (count(array_unique($statuses)) > 1) {
				return 'Создано частично';
			}
			return match ($statuses[0]) {
				'publish' => 'Опубликован',
				'pending' => 'На проверке',
				default => 'Черновик создан',
			};
		}
		if ($live_status !== '' && in_array($state, ['processing_de', 'publishing'], true)) {
			return $live_status;
		}

		$labels = [
			'new' => 'Новый',
			'processing_de' => 'В работе',
			'ready_review' => 'Требует ручной проверки',
			'ready_publish' => 'Готов к публикации',
			'retry_process' => 'На доработке',
			'retry_publish' => 'Ожидает публикации',
			'reserve' => 'Резерв',
			'duplicate' => 'Дубликат',
			'error' => 'Ошибка',
			'rejected' => 'Отклонён',
		];
		$label = $labels[$state] ?? $state;
		if (in_array($state, ['retry_process', 'retry_publish'], true) && $retry_after !== '') {
			$ts = strtotime($retry_after);
			if ($ts) {
				$label .= ' до ' . wp_date('H:i', $ts);
			}
		}
		return $label;
	}

	private static function run_status_label(string $status): string {
		$labels = [
			'idle' => 'Ожидание',
			'running' => 'Выполняется',
			'started' => 'Запущен',
			'finished' => 'Завершён',
			'finished_with_errors' => 'Завершён с ошибками',
		];
		return $labels[$status] ?? $status;
	}

	private static function category_select(string $name, string $selected = '', bool $compact = false): string {
		$html = '<select name="' . esc_attr($name) . '"' . ($compact ? ' class="small-text"' : '') . '>';
		$html .= '<option value="">— выбрать —</option>';
		foreach (EPV2_Taxonomy_Map::categories() as $slug => $label) {
			$html .= '<option value="' . esc_attr($slug) . '"' . selected($selected, $slug, false) . '>' . esc_html($label) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	private static function source_type_select(string $name, string $selected = 'rss'): string {
		$options = [
			'rss' => 'rss',
			'atom' => 'atom',
			'google_news' => 'google_news',
			'scrape' => 'scrape',
			'telegram' => 'telegram',
			'facebook' => 'facebook',
		];
		$html = '<select name="' . esc_attr($name) . '">';
		foreach ($options as $value => $label) {
			$html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
		}
		$html .= '</select><p class="description">Telegram лучше добавлять как публичный канал `https://t.me/channel` или `https://t.me/s/channel`. Facebook лучше заводить либо как публичную страницу/ивенты, либо через RSS-bridge URL.</p>';
		return $html;
	}

	private static function category_label(string $slug): string {
		$categories = EPV2_Taxonomy_Map::categories();
		return $categories[$slug] ?? $slug;
	}

	private static function category_checkboxes(string $name, array $selected, string $group_class, bool $compact = false, bool $collapsed = false): string {
		$summary = empty($selected)
			? 'Выбрать рубрики'
			: implode(', ', array_map([self::class, 'category_label'], $selected));
		$wrapper_open = $collapsed
			? '<details class="' . esc_attr($group_class) . '-details"><summary style="cursor:pointer">' . esc_html($summary) . '</summary>'
			: '';
		$wrapper_close = $collapsed ? '</details>' : '';
		$html = $wrapper_open . '<div class="' . esc_attr($group_class) . '" style="display:flex;flex-wrap:wrap;gap:8px 10px;max-width:420px;margin-top:' . ($collapsed ? '8px' : '0') . '">';
		foreach (EPV2_Taxonomy_Map::categories() as $slug => $label) {
			$checked = in_array($slug, $selected, true) ? ' checked' : '';
			$style = in_array($slug, $selected, true) ? 'background:#e7f0ff;border-color:#2271b1' : '';
			$html .= '<label style="display:inline-flex;align-items:center;gap:4px;border:1px solid #dcdcde;border-radius:999px;padding:' . ($compact ? '2px 8px' : '4px 10px') . ';' . $style . '">';
			$html .= '<input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($slug) . '"' . $checked . '>';
			$html .= '<span>' . esc_html($label) . '</span></label>';
		}
		$html .= '</div>' . $wrapper_close;
		return $html;
	}

	private static function normalize_selected_categories($input): array {
		if (is_array($input)) {
			$parts = $input;
		} else {
			$parts = explode(',', (string) $input);
		}
		$parts = array_values(array_filter(array_map('sanitize_text_field', array_map('trim', $parts))));
		$parts = array_values(array_unique($parts));
		return array_slice($parts, 0, 3);
	}

	private static function post_statuses_for_queue(object $item): array {
		$statuses = [];
		$payload = json_decode((string) ($item->publish_payload ?? ''), true);
		if (is_array($payload) && ! empty($payload['post_ids']) && is_array($payload['post_ids'])) {
			foreach ($payload['post_ids'] as $post_id) {
				$status = get_post_status((int) $post_id);
				if ($status) {
					$statuses[] = $status;
				}
			}
		} elseif (! empty($item->post_id)) {
			$status = get_post_status((int) $item->post_id);
			if ($status) {
				$statuses[] = $status;
			}
		}
		return $statuses;
	}

	private static function queue_published_at(object $item): string {
		$payload = json_decode((string) ($item->publish_payload ?? ''), true);
		$post_ids = is_array($payload['post_ids'] ?? null) ? $payload['post_ids'] : [];
		$primary_id = (int) ($post_ids['de'] ?? $item->post_id ?? 0);
		if ($primary_id <= 0) {
			return '—';
		}
		$post = get_post($primary_id);
		if (! $post instanceof WP_Post || $post->post_status !== 'publish') {
			return '—';
		}
		return (string) $post->post_date;
	}

	private static function queue_published_timestamp(object $item): int {
		$payload = json_decode((string) ($item->publish_payload ?? ''), true);
		$post_ids = is_array($payload['post_ids'] ?? null) ? $payload['post_ids'] : [];
		$primary_id = (int) ($post_ids['de'] ?? $item->post_id ?? 0);
		if ($primary_id <= 0) {
			return 0;
		}
		$post = get_post($primary_id);
		if (! $post instanceof WP_Post || $post->post_status !== 'publish') {
			return 0;
		}
		$published_gmt = (string) ($post->post_date_gmt ?? '');
		if ($published_gmt !== '' && $published_gmt !== '0000-00-00 00:00:00') {
			$timestamp = strtotime($published_gmt . ' UTC');
			if ($timestamp !== false) {
				return (int) $timestamp;
			}
		}
		$timestamp = strtotime((string) ($post->post_date ?? ''));
		return $timestamp !== false ? (int) $timestamp : 0;
	}

	private static function queue_post_link(object $item): string {
		$payload = json_decode((string) ($item->publish_payload ?? ''), true);
		$post_ids = is_array($payload['post_ids'] ?? null) ? $payload['post_ids'] : [];
		$primary_id = (int) ($post_ids['de'] ?? $item->post_id ?? 0);
		if ($primary_id <= 0) {
			return '<span style="color:#8c8f94">—</span>';
		}
		$url = get_permalink($primary_id);
		if (! $url) {
			return '<span style="color:#8c8f94">—</span>';
		}
		return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Открыть пост</a>';
	}

	private static function regen_button(int $item_id, string $lang, string $field, string $style): string {
		$base = wp_nonce_url(
			admin_url('admin-post.php?action=epv2_regenerate_field&id=' . $item_id . '&lang=' . rawurlencode($lang) . '&field=' . rawurlencode($field) . '&style=' . rawurlencode($style)),
			'epv2_regenerate_field_' . $item_id . '_' . $lang . '_' . $field
		);
		$select_id = 'epv2-style-' . $item_id . '-' . $lang . '-' . $field;
		return ' <span style="display:inline-flex;align-items:center;gap:6px;margin-top:6px">' .
			self::style_select('style_dummy_' . $item_id . '_' . $lang . '_' . $field, $style, $select_id, true) .
			'<a class="button button-small epv2-regen-link" data-base="' . esc_url($base) . '" data-style-select="' . esc_attr($select_id) . '" href="' . esc_url($base) . '">Перегенерировать</a></span>';
	}

	private static function style_select(string $name, string $selected, string $id = '', bool $compact = false): string {
		$id_attr = $id !== '' ? ' id="' . esc_attr($id) . '"' : '';
		$class = $compact ? ' class="small-text"' : '';
		return '<select name="' . esc_attr($name) . '"' . $id_attr . $class . '>'
			. '<option value="strict"' . selected($selected, 'strict', false) . '>Строгий</option>'
			. '<option value="analytic"' . selected($selected, 'analytic', false) . '>Аналитический</option>'
			. '<option value="lively"' . selected($selected, 'lively', false) . '>Живой</option>'
			. '</select>';
	}

	private static function media_button(string $target_id, bool $append = false): string {
		return '<button type="button" class="button epv2-media-pick" data-target="' . esc_attr($target_id) . '"' . ($append ? ' data-append="1"' : '') . '>Скрепка</button>';
	}

	private static function media_preview(string $url): string {
		$type = EPV2_Media::detect_type($url);
		if ($type === 'image') {
			return '<p><img src="' . esc_url($url) . '" alt="" style="max-width:100%;height:auto;border-radius:6px"></p>';
		}
		if ($type === 'video') {
			return '<p><video controls preload="metadata" style="max-width:100%;height:auto;border-radius:6px"><source src="' . esc_url($url) . '"></video></p>';
		}
		return '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Видео / embed</a></p>';
	}

	private static function set_manual_notice(string $message, bool $success = true): void {
		set_transient('epv2_manual_notice_' . get_current_user_id(), [
			'success' => $success,
			'message' => $message,
		], 5 * MINUTE_IN_SECONDS);
	}

	private static function editorial_flags(array $payload): string {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$breaking = ! empty($meta['breaking']);
		$top_story = ! empty($meta['top_story']);
		$hours = max(1, min(24, (int) ($meta['breaking_hours'] ?? EPV2_Settings::get('auto_breaking_hours', 6))));
		return
			'<label style="display:inline-flex;align-items:center;gap:6px;margin-right:16px"><input type="checkbox" name="editorial[breaking]"' . checked($breaking, true, false) . '> <span style="color:#c62828;font-weight:700">Breaking News</span></label>' .
			'<label style="display:inline-flex;align-items:center;gap:6px;margin-right:16px"><input type="checkbox" name="editorial[top_story]"' . checked($top_story, true, false) . '> <span style="color:#0f172a;font-weight:700">Top Thema</span></label>' .
			'<label style="display:inline-flex;align-items:center;gap:6px">Часы Breaking <input type="number" min="1" max="24" name="editorial[breaking_hours]" value="' . esc_attr((string) $hours) . '" class="small-text"></label>';
	}

	private static function queue_badges(object $item): string {
		$payload = EPV2_Review::decode_payload((string) ($item->ai_payload ?? ''));
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$parts = [];
		if (! empty($meta['breaking'])) {
			$parts[] = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fff1f2;color:#c62828;font-size:11px;font-weight:700;border:1px solid #fecdd3">Breaking News</span>';
		}
		if (! empty($meta['top_story'])) {
			$parts[] = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#eff6ff;color:#0f172a;font-size:11px;font-weight:700;border:1px solid #bfdbfe">Top Thema</span>';
		}
		if (empty($parts)) {
			return '<span style="color:#8c8f94">—</span>';
		}
		return implode(' ', $parts);
	}

	private static function queue_score_badge(object $item): string {
		$analysis = self::queue_analysis($item);
		if (empty($analysis)) {
			return '<span style="color:#8c8f94">—</span>';
		}
		$score = (int) ($analysis['score'] ?? 0);
		$tier = (string) ($analysis['tier'] ?? '—');
		$bg = match ($tier) {
			'A' => '#fee2e2',
			'B' => '#fef3c7',
			'C' => '#e0f2fe',
			default => '#f3f4f6',
		};
		$color = match ($tier) {
			'A' => '#991b1b',
			'B' => '#92400e',
			'C' => '#075985',
			default => '#6b7280',
		};
		$title = ! empty($analysis['reasons']) ? implode('; ', (array) $analysis['reasons']) : '';
		return '<span title="' . esc_attr($title) . '" style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:11px;font-weight:700">' . esc_html($tier . ' / ' . $score) . '</span>';
	}

	private static function queue_quality_badge(object $item): string {
		$payload = self::queue_cached_payload($item);
		$quality = is_array($payload['_meta']['quality'] ?? null) ? $payload['_meta']['quality'] : [];
		if ($quality === []) {
			return '<span style="color:#8c8f94">—</span>';
		}
		$score = (int) ($quality['score'] ?? 0);
		$warnings = [];
		foreach ((array) ($quality['warnings'] ?? []) as $lang => $lang_warnings) {
			if (is_array($lang_warnings) && $lang_warnings !== []) {
				$warnings[] = strtoupper((string) $lang) . ': ' . implode('; ', array_map('strval', $lang_warnings));
			}
		}
		$bg = $score >= 90 ? '#dcfce7' : ($score >= 80 ? '#fef3c7' : '#fee2e2');
		$color = $score >= 90 ? '#166534' : ($score >= 80 ? '#92400e' : '#991b1b');
		return '<span title="' . esc_attr(implode(' | ', $warnings)) . '" style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:11px;font-weight:700">' . esc_html((string) $score) . '</span>';
	}

	private static function sort_queue_items(array $items, string $orderby, string $order): array {
		$order = $order === 'asc' ? 'asc' : 'desc';
		usort($items, static function ($a, $b) use ($orderby, $order): int {
			$compare = match ($orderby) {
				'id' => ((int) $a->id) <=> ((int) $b->id),
				'state' => strcmp((string) $a->state, (string) $b->state),
				'category' => strcmp((string) ($a->category_final ?: $a->category_proposed), (string) ($b->category_final ?: $b->category_proposed)),
				'published_at' => self::queue_published_timestamp($a) <=> self::queue_published_timestamp($b),
				'priority' => self::queue_priority_value($a) <=> self::queue_priority_value($b),
				'quality' => self::queue_quality_value($a) <=> self::queue_quality_value($b),
				'seo' => self::queue_seo_value($a) <=> self::queue_seo_value($b),
				default => strcmp((string) $a->created_at, (string) $b->created_at),
			};
			if ($compare === 0) {
				$compare = ((int) $a->id) <=> ((int) $b->id);
			}
			return $order === 'asc' ? $compare : -$compare;
		});
		return $items;
	}

	private static function queue_priority_value(object $item): int {
		return (int) ($item->story_score ?? 0);
	}

	private static function queue_quality_value(object $item): int {
		$payload = self::queue_cached_payload($item);
		return (int) (($payload['_meta']['quality']['score'] ?? 0));
	}

	private static function queue_seo_value(object $item): int {
		$payload = self::queue_cached_payload($item);
		return (int) (($payload['_meta']['seo_quality']['score'] ?? 0));
	}

	private static function review_blockers(object $item, array $payload): array {
		$blockers = [];
		$error = trim((string) ($item->error_message ?? ''));
		if ($error !== '') {
			$blockers[] = $error;
		}

		$quality_sets = [
			'Редакционное качество' => is_array($payload['_meta']['quality'] ?? null) ? $payload['_meta']['quality'] : [],
			'SEO' => is_array($payload['_meta']['seo_quality'] ?? null) ? $payload['_meta']['seo_quality'] : [],
			'Готовность к выпуску' => is_array($payload['_meta']['release_quality'] ?? null) ? $payload['_meta']['release_quality'] : [],
			'Google preflight' => is_array($payload['_meta']['google_quality'] ?? null) ? $payload['_meta']['google_quality'] : [],
		];

		foreach ($quality_sets as $label => $quality) {
			if (! is_array($quality) || $quality === []) {
				continue;
			}
			$warnings = $quality['warnings'] ?? [];
			if (! is_array($warnings) || $warnings === []) {
				continue;
			}
			foreach ($warnings as $key => $warning_group) {
				if (is_array($warning_group)) {
					foreach ($warning_group as $warning) {
						$warning = trim((string) $warning);
						if ($warning === '') {
							continue;
						}
						$prefix = is_string($key) ? strtoupper($key) . ': ' : '';
						$blockers[] = $label . ': ' . $prefix . $warning;
					}
					continue;
				}
				$warning = trim((string) $warning_group);
				if ($warning !== '') {
					$blockers[] = $label . ': ' . $warning;
				}
			}
		}

		return array_values(array_unique($blockers));
	}

	private static function queue_cached_payload(object $item): array {
		$payload = EPV2_Review::decode_payload((string) ($item->ai_payload ?? ''));
		return is_array($payload) ? $payload : [];
	}

	private static function queue_sort_link(string $field, string $label, string $current, string $order, string $state_filter, string $category_filter): string {
		$next = ($current === $field && $order === 'desc') ? 'asc' : 'desc';
		$url = add_query_arg([
			'page' => 'epv2-queue',
			'orderby' => $field,
			'order' => $next,
			'state_filter' => $state_filter,
			'category_filter' => $category_filter,
		], admin_url('admin.php'));
		$arrow = $current === $field ? ($order === 'desc' ? ' ↓' : ' ↑') : '';
		return '<a href="' . esc_url($url) . '">' . esc_html($label . $arrow) . '</a>';
	}

	private static function queue_sort_options(): array {
		return [
			'created_at' => 'По времени создания',
			'published_at' => 'По времени публикации',
			'id' => 'По ID',
			'priority' => 'По приоритету',
			'quality' => 'По качеству',
			'seo' => 'По SEO',
			'state' => 'По статусу',
			'category' => 'По категориям',
		];
	}

	private static function queue_state_options(): array {
		return [
			'new' => 'Новый',
			'processing_de' => 'В работе',
			'ready_review' => 'Требует ручной проверки',
			'ready_publish' => 'Готов к публикации',
			'retry_process' => 'На доработке',
			'retry_publish' => 'Ожидает публикации',
			'reserve' => 'Резерв',
			'draft_created' => 'Черновик создан',
			'pending_review' => 'На проверке',
			'partially_created' => 'Создан частично',
			'published' => 'Опубликовано',
			'duplicate' => 'Дубликат',
			'rejected' => 'Отклонено',
			'error' => 'Ошибка',
		];
	}

	private static function queue_action_hint(string $state): string {
		return match ($state) {
			'new' => 'Материал ждёт или проходит автоматическую доработку.',
			'processing_de' => 'Материал сейчас обрабатывается и усиливается.',
			'ready_review' => 'Автоматика больше не ведёт этот материал дальше сама. Пакет требует ручной редакционной проверки и решения: доработать, опубликовать вручную или отклонить.',
			'retry_process' => 'Материал автоматически вернётся в работу после внутреннего сбоя или недотянутого качества.',
			'retry_publish' => 'Материал временно ждёт повторной попытки публикации.',
			'ready_publish' => 'Материал уже готов к публикации.',
			'reserve' => 'Материал сохранён как запасной сильный кандидат внутри своей рубрики.',
			'draft_created' => 'На сайте уже созданы черновики; можно публиковать после проверки.',
			'pending_review' => 'Материал создан как pending и ждёт проверки на сайте.',
			'partially_created' => 'Часть языковых версий создана; сначала проверь пакет.',
			'published' => 'Материал уже опубликован.',
			'duplicate' => 'Это дубль; публикация отключена.',
			'rejected' => 'Материал отсеян фильтрами и не пойдёт в публикацию.',
			'error' => 'Есть ошибка обработки; сначала открой и проверь материал.',
			default => 'Сначала открой материал и проверь пакет.',
		};
	}

	private static function queue_seo_badge(object $item): string {
		$payload = self::queue_cached_payload($item);
		$seo = is_array($payload['_meta']['seo_quality'] ?? null) ? $payload['_meta']['seo_quality'] : [];
		if ($seo === []) {
			return '<span style="color:#8c8f94">—</span>';
		}
		$score = (int) ($seo['score'] ?? 0);
		$warnings = [];
		foreach ((array) ($seo['warnings'] ?? []) as $lang => $lang_warnings) {
			if (is_array($lang_warnings) && $lang_warnings !== []) {
				$warnings[] = strtoupper((string) $lang) . ': ' . implode('; ', array_map('strval', $lang_warnings));
			}
		}
		$bg = $score >= 90 ? '#dbeafe' : ($score >= 78 ? '#e0f2fe' : '#fee2e2');
		$color = $score >= 90 ? '#1d4ed8' : ($score >= 78 ? '#075985' : '#991b1b');
		return '<span title="' . esc_attr(implode(' | ', $warnings)) . '" style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:11px;font-weight:700">' . esc_html((string) $score) . '</span>';
	}

	private static function queue_analysis(object $item): array {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		return is_array($notes['selection'] ?? null) ? $notes['selection'] : [];
	}

	private static function usage_hint(string $provider, string $model, array $usage, string $element_id = ''): string {
		$row = (array) ($usage[$provider][$model] ?? []);
		$requests = (int) ($row['requests'] ?? 0);
		$tokens = (int) ($row['tokens'] ?? 0);
		$id_attr = $element_id !== '' ? ' id="' . esc_attr($element_id) . '"' : '';
		return '<p' . $id_attr . ' class="description" style="margin-top:6px" data-requests="' . esc_attr((string) $requests) . '" data-tokens="' . esc_attr((string) $tokens) . '">Сегодня: вызовы ' . esc_html((string) $requests) . ', токены ' . esc_html((string) $tokens) . '.</p>';
	}

	private static function admin_script(): string {
		$model_map = wp_json_encode(EPV2_Settings::ai_model_options(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$usage_map = wp_json_encode(EPV2_Stats::ai_usage_today(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return <<<JS
jQuery(function($){
  const epv2QueueSnapshotUrl = window.ajaxurl
    ? (window.ajaxurl + '?action=epv2_queue_snapshot')
    : '';
  const epv2ModelMap = {$model_map};
  const epv2UsageMap = {$usage_map};
  function syncUsage(providerSelector, modelSelector, hintSelector) {
    const provider = $(providerSelector);
    const model = $(modelSelector);
    const hint = $(hintSelector);
    if (!provider.length || !model.length || !hint.length) return;
    const render = function() {
      const providerValue = provider.val();
      const modelValue = model.val();
      const row = (((epv2UsageMap || {})[providerValue] || {})[modelValue] || {});
      const requests = Number(row.requests || 0);
      const tokens = Number(row.tokens || 0);
      hint.text('Сегодня: вызовы ' + requests + ', токены ' + tokens + '.');
    };
    provider.on('change', render);
    model.on('change', render);
    render();
  }
  function syncModels(providerSelector, modelSelector) {
    const provider = $(providerSelector);
    const model = $(modelSelector);
    if (!provider.length || !model.length) return;
    const rebuild = function() {
      const providerValue = provider.val();
      const options = epv2ModelMap[providerValue] || {};
      const current = model.val();
      model.empty();
      Object.keys(options).forEach(function(key){
        const option = $('<option></option>').val(key).text(options[key]);
        if (key === current) {
          option.prop('selected', true);
        }
        model.append(option);
      });
      if (!model.val() && model.find('option').length) {
        model.prop('selectedIndex', 0);
      }
    };
    provider.on('change', rebuild);
    rebuild();
  }
  $(document).on('click', '.epv2-media-pick', function(e){
    e.preventDefault();
    const target = $('#' + $(this).data('target'));
    const append = !!$(this).data('append');
    const frame = wp.media({ title: 'Выбор медиа', button: { text: 'Использовать' }, multiple: false, library: { type: ['image','video'] } });
    frame.on('select', function(){
      const media = frame.state().get('selection').first().toJSON();
      if (append && target.length) {
        const current = (target.val() || '').trim();
        target.val(current ? current + "\\n" + (media.url || '') : (media.url || ''));
      } else {
        target.val(media.url || '');
      }
    });
    frame.open();
  });
  $(document).on('click', '.epv2-regen-link', function(){
    const selectId = $(this).data('style-select');
    const base = $(this).data('base');
    const style = $('#' + selectId).val();
    if (style) {
      const url = new URL(base, window.location.origin);
      url.searchParams.set('style', style);
      $(this).attr('href', url.toString());
    }
  });
  syncModels('#epv2-ai-provider', '#epv2-ai-model');
  syncModels('#epv2-ai-fallback-provider', '#epv2-ai-fallback-model');
  syncUsage('#epv2-ai-provider', '#epv2-ai-model', '#epv2-ai-usage-primary');
  syncUsage('#epv2-ai-fallback-provider', '#epv2-ai-fallback-model', '#epv2-ai-usage-fallback');
  let queueRefreshing = false;
  let refreshQueueBlocks = null;
  const renderCollectCountdown = function() {
    const node = document.getElementById('epv2-next-collect-countdown');
    if (!node) return;
    let target = Number(node.getAttribute('data-target') || 0);
    const running = node.getAttribute('data-running') === '1';
    let diff = Math.max(0, target - Date.now());
    if (running && (!target || diff <= 0)) {
      node.textContent = 'идёт сбор';
      return;
    }
    if (!target) {
      node.textContent = '00:00';
      return;
    }
    if (diff <= 0) {
      node.textContent = '00:00';
      if (typeof refreshQueueBlocks === 'function') {
        refreshQueueBlocks();
      }
      return;
    }
    const totalSeconds = Math.floor(diff / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const hh = String(hours).padStart(2, '0');
    const mm = String(minutes).padStart(2, '0');
    const ss = String(seconds).padStart(2, '0');
    node.textContent = hours > 0 ? (hh + ':' + mm + ':' + ss) : (mm + ':' + ss);
  };
  const renderPublishCountdown = function() {
    const node = document.getElementById('epv2-next-publish-countdown');
    if (!node) return;
    let target = Number(node.getAttribute('data-target') || 0);
    if (!target) {
      node.textContent = '00:00';
      return;
    }
    let diff = Math.max(0, target - Date.now());
    if (diff <= 0) {
      node.textContent = '00:00';
      if (typeof refreshQueueBlocks === 'function') {
        refreshQueueBlocks();
      }
      return;
    }
    const totalSeconds = Math.floor(diff / 1000);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const hours = Math.floor(totalSeconds / 3600);
    const hh = String(hours).padStart(2, '0');
    const mm = String(minutes).padStart(2, '0');
    const ss = String(seconds).padStart(2, '0');
    node.textContent = hours > 0 ? (hh + ':' + mm + ':' + ss) : (mm + ':' + ss);
  };
  renderCollectCountdown();
  renderPublishCountdown();
  window.setInterval(renderCollectCountdown, 1000);
  window.setInterval(renderPublishCountdown, 1000);
  const queueBlocks = document.getElementById('epv2-queue-blocks');
  if (queueBlocks && epv2QueueSnapshotUrl) {
    const applySnapshotTargets = function(response) {
      if (!response || !response.success || !response.data) {
        return;
      }
      const collectNode = document.getElementById('epv2-next-collect-countdown');
      if (collectNode && typeof response.data.next_collect !== 'undefined') {
        collectNode.setAttribute('data-target', String(Number(response.data.next_collect || 0) * 1000));
        collectNode.setAttribute('data-running', response.data.collect_running ? '1' : '0');
      }
      const publishNode = document.getElementById('epv2-next-publish-countdown');
      if (publishNode && typeof response.data.next_publish !== 'undefined') {
        publishNode.setAttribute('data-target', String(Number(response.data.next_publish || 0) * 1000));
      }
    };
    refreshQueueBlocks = function() {
      if (queueRefreshing) return;
      queueRefreshing = true;
      const params = new URLSearchParams(window.location.search);
      const url = new URL(epv2QueueSnapshotUrl, window.location.origin);
      ['orderby', 'order', 'state_filter', 'category_filter'].forEach(function(key){
        if (params.get(key)) {
          url.searchParams.set(key, params.get(key));
        }
      });
      $.get(url.toString())
        .done(function(response){
          if (response && response.success && response.data && response.data.html) {
            queueBlocks.innerHTML = response.data.html;
            applySnapshotTargets(response);
            renderCollectCountdown();
            renderPublishCountdown();
          }
        })
        .always(function(){
          queueRefreshing = false;
        });
    };
    window.setInterval(refreshQueueBlocks, 10000);
  }
});
JS;
	}
}
