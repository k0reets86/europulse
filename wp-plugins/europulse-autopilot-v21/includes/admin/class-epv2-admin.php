<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Admin {
	private const QUEUE_PAGE_FETCH_LIMIT = 80;
	private const QUEUE_SNAPSHOT_CACHE_TTL = 2;
	private const QUEUE_SNAPSHOT_REFRESH_MS = 5000;
	private const QUEUE_SNAPSHOT_REQUEST_COOLDOWN = 2;

	private static function require_manage_capability(): void {
		if (! current_user_can('manage_europulse_autopilot')) {
			wp_die('Недостаточно прав.');
		}
	}

	private static function require_source_capability(): void {
		if (! current_user_can('edit_europulse_autopilot_sources')) {
			wp_die('Недостаточно прав.');
		}
	}

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
		add_action('admin_post_epv2_promote_manual_review', [self::class, 'promote_manual_review']);
		add_action('admin_post_epv2_reject_manual_review', [self::class, 'reject_manual_review']);
		add_action('admin_post_epv2_reprocess_manual_review', [self::class, 'reprocess_manual_review']);
		add_action('admin_post_epv2_reset_schedule_to_defaults', [self::class, 'reset_schedule_to_defaults']);
		add_action('admin_post_epv2_clear_rejected_queue', [self::class, 'clear_rejected_queue']);
		add_action('admin_post_epv2_delete_queue_item', [self::class, 'delete_queue_item']);
		add_action('admin_post_epv2_delete_published_post', [self::class, 'delete_published_post']);
		add_action('admin_post_epv2_update_queue_category', [self::class, 'update_queue_category']);
		add_action('admin_post_epv2_run_collect', [self::class, 'run_collect']);
		add_action('admin_post_epv2_run_process', [self::class, 'run_process']);
		add_action('admin_post_epv2_run_publish', [self::class, 'run_publish']);
		add_action('admin_post_epv2_pause_automation', [self::class, 'pause_automation']);
		add_action('admin_post_epv2_resume_automation', [self::class, 'resume_automation']);
		add_action('admin_post_epv2_resume_automation_without_collect', [self::class, 'resume_automation_without_collect']);
		add_action('admin_post_epv2_pause_collect', [self::class, 'pause_collect']);
		add_action('admin_post_epv2_resume_collect', [self::class, 'resume_collect']);
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
		// v2.1: per-block AJAX regeneration via Python worker
		add_action('wp_ajax_epv2_regen_block', [self::class, 'regen_block']);
	}

	public static function enqueue_assets(string $hook): void {
		if (strpos($hook, 'epv2') === false) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script('jquery-core', self::admin_script());
	}

	public static function menus(): void {
		$menu_cap = 'manage_options';
		$page_cap = 'manage_europulse_autopilot';
		add_menu_page('EuroPulse AutoPilot v21', 'AutoPilot v21', $menu_cap, 'epv2-dashboard', [self::class, 'dashboard'], 'dashicons-rss', 58);
		add_submenu_page('epv2-dashboard', 'Обзор', 'Обзор', $page_cap, 'epv2-dashboard', [self::class, 'dashboard']);
		add_submenu_page('epv2-dashboard', 'Источники', 'Источники', $page_cap, 'epv2-sources', [self::class, 'sources']);
		add_submenu_page('epv2-dashboard', 'Очередь', 'Очередь', $page_cap, 'epv2-queue', [self::class, 'queue']);
		add_submenu_page('epv2-dashboard', 'Настройки', 'Настройки', $page_cap, 'epv2-settings', [self::class, 'settings']);
		add_submenu_page('epv2-dashboard', 'Ручной режим', 'Ручной режим', $page_cap, 'epv2-manual', [self::class, 'manual']);
		add_submenu_page('epv2-dashboard', 'Проверка материала', 'Проверка материала', $page_cap, 'epv2-review', [self::class, 'review']);
		add_submenu_page('epv2-dashboard', 'Логи', 'Логи', $page_cap, 'epv2-logs', [self::class, 'logs']);
		add_submenu_page('epv2-dashboard', 'Запуски', 'Запуски', $page_cap, 'epv2-runs', [self::class, 'runs']);
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
		$collect_paused = EPV2_Jobs::collect_paused();
		$progress = get_option('epv2_collect_progress', []);
		$last_collect = EPV2_Runs::latest('collect');
		$last_payload = $last_collect && ! empty($last_collect->payload) ? json_decode((string) $last_collect->payload, true) : [];
		$issues = self::latest_human_issues();
		echo '<div class="wrap"><h1>EuroPulse AutoPilot</h1>';
		self::render_automation_safeguard_notice();
		echo '<p>Текущий контракт сайта загружен для языков: <strong>' . esc_html(implode(', ', EPV2_Site_Profile::get_languages())) . '</strong></p>';
		echo '<div style="margin:16px 0;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:980px">';
		echo '<h2 style="margin:0 0 8px">Автоматический режим</h2>';
		echo '<p style="margin:0 0 12px;color:#50575e">Полная пауза останавливает всё. Пауза сбора останавливает только intake новых материалов, но обработка и публикация уже собранной очереди продолжаются.</p>';
		echo '<p style="margin:0 0 8px;color:#50575e">Сейчас: <strong style="color:' . ($automation_paused ? '#b45309' : '#15803d') . '">' . ($automation_paused ? 'Пауза' : 'Пуск') . '</strong></p>';
		$collect_status_label = $automation_paused ? 'Остановлен полной паузой' : ($collect_paused ? 'Остановлен' : 'Активен');
		$collect_status_color = ($automation_paused || $collect_paused) ? '#b45309' : '#15803d';
		echo '<p style="margin:0 0 8px;color:#50575e">Сбор: <strong style="color:' . $collect_status_color . '">' . $collect_status_label . '</strong></p>';
		echo '<p style="margin:0">';
		if ($automation_paused) {
			echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_resume_automation'), 'epv2_resume_automation')) . '">Пуск всё</a> ';
			echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_resume_automation_without_collect'), 'epv2_resume_automation_without_collect')) . '">Пуск без сбора</a>';
		} else {
			echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_pause_automation'), 'epv2_pause_automation')) . '">Полная пауза</a> ';
			if ($collect_paused) {
				echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_resume_collect'), 'epv2_resume_collect')) . '">Возобновить сбор</a>';
			} else {
				echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_pause_collect'), 'epv2_pause_collect')) . '">Пауза сбора</a>';
			}
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

		// Phase 3 — schedule preview block on the dashboard.
		// Operator sees the current 7-window schedule, current mode, and
		// can reset to architecture-default if previously customised.
		if (class_exists('EPV2_Time_Planner')) {
			$profile = (array) (EPV2_Settings::get('time_schedule_profile', EPV2_Time_Planner::defaults()));
			$windows = (array) ($profile['windows'] ?? []);
			$current_mode = EPV2_Time_Planner::current_mode_label();
			echo '<div style="margin:16px 0;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:980px">';
			echo '<h2 style="margin:0 0 8px">Расписание публикаций</h2>';
			echo '<p style="margin:0 0 12px;color:#50575e">Текущий режим: <strong>' . esc_html($current_mode) . '</strong>. Расписание задаётся в настройке <code>epv2_settings.time_schedule_profile</code>.</p>';
			if ($windows !== []) {
				echo '<table class="widefat striped" style="max-width:780px"><thead><tr><th>Слот</th><th>Режим</th><th>Таймер публикации</th><th>Сбор</th></tr></thead><tbody>';
				foreach ($windows as $w) {
					$start = (string) ($w['start'] ?? '');
					$end = (string) ($w['end'] ?? '');
					$mode = (string) ($w['mode'] ?? '');
					$publish_minutes = (array) ($w['publish_minutes'] ?? []);
					$collect_minutes = (array) ($w['collect_minutes'] ?? []);
					$pub_count = count($publish_minutes);
					$timer_label = $pub_count === 0
						? '— (пауза)'
						: ($pub_count >= 12 ? '5 мин' : ($pub_count >= 8 ? '8 мин' : ($pub_count >= 4 ? '15 мин' : sprintf('%d слотов в час', $pub_count))));
					$col_label = $collect_minutes === [] ? '—' : implode(', ', array_map(fn($m) => sprintf('%02d', $m), $collect_minutes));
					echo '<tr>';
					echo '<td>' . esc_html($start) . '–' . esc_html($end) . '</td>';
					echo '<td><code>' . esc_html($mode) . '</code></td>';
					echo '<td>' . esc_html($timer_label) . '</td>';
					echo '<td>:' . esc_html($col_label) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
			echo '<p style="margin-top:12px"><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_reset_schedule_to_defaults'), 'epv2_reset_schedule_to_defaults')) . '" onclick="return confirm(\'Сбросить расписание к архитектурному дефолту (7 окон)?\')">Сбросить к архитектурному дефолту</a></p>';
			echo '</div>';
		}

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
		$editing_id = (int) ($_GET['edit_source'] ?? 0);
		$editing_source = $editing_id > 0 ? EPV2_Sources::get($editing_id) : null;
		$source_form = [
			'id' => $editing_source ? (int) $editing_source->id : 0,
			'name' => $editing_source ? (string) $editing_source->name : '',
			'type' => $editing_source ? (string) $editing_source->type : 'rss',
			'url' => $editing_source ? (string) $editing_source->url : '',
			'language' => $editing_source ? (string) $editing_source->language : 'de',
			'category_bias' => $editing_source ? (string) $editing_source->category_bias : '',
			'priority' => $editing_source ? (int) $editing_source->priority : 5,
			'fetch_interval' => $editing_source ? (int) $editing_source->fetch_interval : 1800,
			'risk_level' => $editing_source ? (string) $editing_source->risk_level : 'low',
			'parse_rules_json' => $editing_source && ! empty($editing_source->parse_rules) ? wp_json_encode(json_decode((string) $editing_source->parse_rules, true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '',
			'notes' => $editing_source ? (string) $editing_source->notes : '',
			'is_active' => $editing_source ? ! empty($editing_source->is_active) : true,
		];
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
		echo '<h2>' . esc_html($editing_source ? 'Редактировать источник' : 'Добавить источник') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('epv2_save_source');
		echo '<input type="hidden" name="action" value="epv2_save_source">';
		if ($source_form['id'] > 0) {
			echo '<input type="hidden" name="id" value="' . (int) $source_form['id'] . '">';
		}
		echo '<table class="form-table"><tbody>';
		self::row('Название', '<input name="name" class="regular-text" required value="' . esc_attr((string) $source_form['name']) . '">');
		self::row('Тип', self::source_type_select('type', (string) $source_form['type']));
		self::row('URL', '<input name="url" class="regular-text" required value="' . esc_attr((string) $source_form['url']) . '">');
		self::row('Язык', '<input name="language" value="' . esc_attr((string) $source_form['language']) . '" class="small-text">');
		self::row('Целевая рубрика', self::category_select('category_bias', (string) $source_form['category_bias']));
		self::row('Приоритет', '<input name="priority" value="' . (int) $source_form['priority'] . '" type="number" min="1" max="10" class="small-text">');
		self::row('Интервал опроса (сек)', '<input name="fetch_interval" value="' . (int) $source_form['fetch_interval'] . '" type="number" min="300" class="small-text">');
		self::row('Риск', '<select name="risk_level"><option value="safe"' . selected((string) $source_form['risk_level'], 'safe', false) . '>safe</option><option value="low"' . selected((string) $source_form['risk_level'], 'low', false) . '>low</option><option value="moderate"' . selected((string) $source_form['risk_level'], 'moderate', false) . '>moderate</option><option value="high"' . selected((string) $source_form['risk_level'], 'high', false) . '>high</option></select>');
		self::row('Parse rules (JSON)', '<textarea name="parse_rules_json" rows="6" class="large-text code" placeholder=\'{"listing_xpath":"//main//a[@href]"}\'>' . esc_textarea((string) $source_form['parse_rules_json']) . '</textarea>');
		self::row('Заметки / лицензия', '<textarea name="notes" rows="5" class="large-text">' . esc_textarea((string) $source_form['notes']) . '</textarea>');
		self::row('Статус', '<label><input type="checkbox" name="is_active"' . checked(! empty($source_form['is_active']), true, false) . '> включен</label>');
		echo '</tbody></table>';
		submit_button($editing_source ? 'Сохранить изменения' : 'Сохранить источник');
		if ($editing_source) {
			echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=epv2-sources')) . '">Отменить редактирование</a>';
		}
		echo '</form>';

		echo '<h2>Текущие источники</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Название</th><th>Тип</th><th>Язык</th><th>Категория</th><th>Приоритет</th><th>Статус</th><th>Последняя ошибка</th><th>Действия</th></tr></thead><tbody>';
		foreach ($sources as $source) {
			echo '<tr>';
			echo '<td>' . (int) $source->id . '</td><td>' . esc_html($source->name) . '</td><td>' . esc_html($source->type) . '</td><td>' . esc_html($source->language) . '</td><td>' . esc_html(self::category_label((string) $source->category_bias)) . '</td><td>' . (int) $source->priority . '</td><td>' . esc_html(self::source_status_label((bool) $source->is_active)) . '</td><td>' . esc_html(wp_trim_words((string) $source->last_error, 10, '')) . '</td><td>';
			echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=epv2-sources&edit_source=' . (int) $source->id)) . '">Изменить</a> ';
			echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_toggle_source&id=' . (int) $source->id), 'epv2_toggle_source_' . (int) $source->id)) . '">Вкл/выкл</a> ';
			echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_test_source&id=' . (int) $source->id), 'epv2_test_source_' . (int) $source->id)) . '">Проверить</a> ';
			echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_delete_source&id=' . (int) $source->id), 'epv2_delete_source_' . (int) $source->id)) . '" onclick="return confirm(\'Удалить источник?\')">Удалить</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function queue(): void {
		$orderby = sanitize_key((string) ($_GET['orderby'] ?? 'created_at'));
		$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
		$state_filter = sanitize_key((string) ($_GET['state_filter'] ?? ''));
		$category_filter = sanitize_title((string) ($_GET['category_filter'] ?? ''));
		$category_options = EPV2_Taxonomy_Map::categories();
		$automation_paused = EPV2_Jobs::automation_paused();
		$collect_paused = EPV2_Jobs::collect_paused();
		$progress = get_option('epv2_collect_progress', []);
		$next_collect = EPV2_Jobs::next_collect_timestamp();
		echo '<div class="wrap"><h1>Очередь</h1>';
		self::render_automation_safeguard_notice();
		$queue_notice = sanitize_key((string) ($_GET['queue_notice'] ?? ''));
		if ($queue_notice !== '') {
			$queue_transient = get_transient('epv2_queue_notice_' . get_current_user_id());
			if ($queue_transient !== false) {
				delete_transient('epv2_queue_notice_' . get_current_user_id());
			}
			$message = match ($queue_notice) {
				'not_publish_ready' => 'Материал не дотянул до полного publish-grade и не может быть переведён в готово к публикации.',
				'publish_blocked' => 'Публикация остановлена: материал не прошёл полный quality gate.',
				'published_now' => 'Материал опубликован вручную.',
				'publish_failed' => is_array($queue_transient) && ! empty($queue_transient['message']) ? (string) $queue_transient['message'] : 'Публикация не выполнена.',
				default => '',
			};
			if ($message !== '') {
				$is_success_notice = $queue_notice === 'published_now';
				echo '<div class="notice ' . esc_attr($is_success_notice ? 'notice-success' : 'notice-error') . '"><p>' . esc_html($message) . '</p></div>';
			}
		}
		echo '<div id="epv2-queue-summary" style="margin:12px 0 16px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:980px">';
		echo '<h2 style="margin:0 0 8px">Следующий сбор</h2>';
		$collect_status_label = $automation_paused ? 'Полная пауза' : ($collect_paused ? 'Сбор остановлен' : 'Работает');
		$collect_status_color = ($automation_paused || $collect_paused) ? '#b45309' : '#15803d';
		echo '<p style="margin:0 0 8px;color:#50575e">Статус: <strong style="color:' . esc_attr($collect_status_color) . '">' . esc_html($collect_status_label) . '</strong></p>';
		if (! $automation_paused && ! $collect_paused && $next_collect) {
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
		} elseif ($collect_paused) {
			echo '<p style="margin:0;color:#50575e">Сбор новых материалов остановлен. Обработка уже собранной очереди и публикация готовых продолжаются.</p>';
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
		echo '<form id="epv2-bulk-delete-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
		wp_nonce_field('epv2_delete_queue_items');
		echo '<input type="hidden" name="action" value="epv2_delete_queue_items">';
		echo '<input type="hidden" name="ids_csv" id="epv2-bulk-delete-ids" value="">';
		echo '<button class="button button-secondary" type="submit" onclick="var ids=[...document.querySelectorAll(\'.epv2-queue-check:checked\')].map(function(cb){return cb.value;}); if(!ids.length){alert(\'Выберите материалы для удаления\'); return false;} document.getElementById(\'epv2-bulk-delete-ids\').value=ids.join(\',\'); return confirm(\'Удалить выбранные материалы?\');">Удалить выбранные</button>';
		echo '</form>';

		// Phase 3 — manual_review bulk actions visible only when filtering
		// that state. Three buttons share the same JS pattern as bulk-delete.
		if ($state_filter === 'manual_review') {
			echo '<form id="epv2-promote-mr-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
			wp_nonce_field('epv2_promote_manual_review');
			echo '<input type="hidden" name="action" value="epv2_promote_manual_review">';
			echo '<input type="hidden" name="ids_csv" id="epv2-promote-mr-ids" value="">';
			echo '<button class="button button-primary" type="submit" onclick="var ids=[...document.querySelectorAll(\'.epv2-queue-check:checked\')].map(function(cb){return cb.value;}); if(!ids.length){alert(\'Выберите материалы для публикации\'); return false;} document.getElementById(\'epv2-promote-mr-ids\').value=ids.join(\',\'); return confirm(\'Отправить в публикацию: \'+ids.length+\' материал(ов)?\');">→ В публикацию</button>';
			echo '</form>';
			echo '<form id="epv2-reprocess-mr-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
			wp_nonce_field('epv2_reprocess_manual_review');
			echo '<input type="hidden" name="action" value="epv2_reprocess_manual_review">';
			echo '<input type="hidden" name="ids_csv" id="epv2-reprocess-mr-ids" value="">';
			echo '<button class="button" type="submit" onclick="var ids=[...document.querySelectorAll(\'.epv2-queue-check:checked\')].map(function(cb){return cb.value;}); if(!ids.length){alert(\'Выберите материалы для повторной обработки\'); return false;} document.getElementById(\'epv2-reprocess-mr-ids\').value=ids.join(\',\'); return confirm(\'Перегенерить заново: \'+ids.length+\' материал(ов)? Сбросит story_card и пропустит через полный пайплайн.\');">↻ Перегенерить</button>';
			echo '</form>';
			echo '<form id="epv2-reject-mr-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
			wp_nonce_field('epv2_reject_manual_review');
			echo '<input type="hidden" name="action" value="epv2_reject_manual_review">';
			echo '<input type="hidden" name="ids_csv" id="epv2-reject-mr-ids" value="">';
			echo '<button class="button" type="submit" onclick="var ids=[...document.querySelectorAll(\'.epv2-queue-check:checked\')].map(function(cb){return cb.value;}); if(!ids.length){alert(\'Выберите материалы для отклонения\'); return false;} document.getElementById(\'epv2-reject-mr-ids\').value=ids.join(\',\'); return confirm(\'Отклонить: \'+ids.length+\' материал(ов)?\');">✕ Отклонить</button>';
			echo '</form>';
		}

		echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_clear_queue'), 'epv2_clear_queue')) . '" onclick="return confirm(\'Очистить всю очередь?\')">Очистить очередь</a>';
		echo '<div id="epv2-queue-blocks">';
		echo self::queue_lightweight_blocks_html();
		echo '</div>';
		echo '</div>';
	}

	public static function queue_snapshot(): void {
		if (! check_ajax_referer('epv2_queue_snapshot', '_wpnonce', false)) {
			wp_send_json_error(['message' => 'invalid_nonce'], 403);
		}
		if (! current_user_can('manage_europulse_autopilot')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		$orderby = sanitize_key((string) ($_GET['orderby'] ?? 'created_at'));
		$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
		$state_filter = sanitize_key((string) ($_GET['state_filter'] ?? ''));
		$category_filter = sanitize_title((string) ($_GET['category_filter'] ?? ''));
		$cache_key = 'epv2_queue_snapshot_' . get_current_user_id() . '_' . md5(wp_json_encode([$orderby, $order, $state_filter, $category_filter]));
		$guard_key = 'epv2_queue_snapshot_guard_' . get_current_user_id();
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			wp_send_json_success($cached);
		}
		$last_request_at = (int) get_transient($guard_key);
		if ($last_request_at > 0 && ($last_request_at + self::QUEUE_SNAPSHOT_REQUEST_COOLDOWN) > time()) {
			wp_send_json_success(self::queue_lightweight_snapshot_payload());
		}
		set_transient($guard_key, time(), self::QUEUE_SNAPSHOT_REQUEST_COOLDOWN);
		$snapshot = self::queue_snapshot_payload($orderby, $order, $state_filter, $category_filter);
		set_transient($cache_key, $snapshot, self::QUEUE_SNAPSHOT_CACHE_TTL);
		wp_send_json_success($snapshot);
	}

	private static function render_automation_safeguard_notice(): void {
		$safeguard = EPV2_Jobs::automation_safeguard_state();
		if (empty($safeguard['active'])) {
			return;
		}
		$detected_at = (int) ($safeguard['detected_at'] ?? 0);
		$detected_label = $detected_at > 0 ? wp_date('Y-m-d H:i:s', $detected_at) : 'только что';
		$reason = trim((string) ($safeguard['reason'] ?? ''));
		$item_id = (int) ($safeguard['item_id'] ?? 0);
		echo '<div class="notice notice-error"><p><strong>Защита остановила автоматизацию.</strong> ';
		echo esc_html($reason !== '' ? $reason : 'Обработка была аварийно прервана.');
		if ($item_id > 0) {
			echo ' ' . esc_html('Проблемный материал: #' . $item_id . '.');
		}
		echo ' ' . esc_html('Время фиксации: ' . $detected_label . '.');
		echo '</p></div>';
	}

	private static function queue_lightweight_snapshot_payload(): array {
		$progress = get_option('epv2_collect_progress', []);
		$collect_paused = EPV2_Jobs::collect_paused();
		$automation_paused = EPV2_Jobs::automation_paused();
		$active_id = self::active_queue_item_id();
		$active_items = $active_id > 0 ? self::queue_light_rows_by_ids([$active_id]) : [];
		$new_items = self::queue_light_rows_by_states(['new', 'retry_process', 'ready_review', 'reserve', 'processing_de'], 30, [$active_id]);
		$publish_items = self::queue_light_rows_by_states(['ready_publish', 'retry_publish', 'publishing'], 30);
		$rejected_items = self::queue_light_rows_by_states(['rejected', 'error', 'duplicate'], 30);
		$published_items = self::queue_light_rows_by_states(['published'], 30);
		$next_publish = self::queue_next_publish_timestamp($publish_items);
		$sections = self::queue_lightweight_sections_payload(
			$active_items,
			$new_items,
			$publish_items,
			$rejected_items,
			$published_items,
			$automation_paused,
			$next_publish
		);
		return [
			'html' => implode('', $sections),
			'sections' => $sections,
			'next_collect' => $collect_paused ? 0 : (int) (EPV2_Jobs::next_collect_timestamp() ?: 0),
			'next_publish' => (int) ($next_publish ?: 0),
			'collect_running' => is_array($progress) && (($progress['status'] ?? '') === 'running'),
			'throttled' => true,
		];
	}

	private static function queue_snapshot_payload(string $orderby, string $order, string $state_filter, string $category_filter): array {
		$progress = get_option('epv2_collect_progress', []);
		$items = EPV2_Queue::get_queue_items_summary([
			'limit' => self::queue_page_fetch_limit($state_filter),
		]);
		if ($state_filter !== '') {
			$items = array_values(array_filter($items, static function ($item) use ($state_filter) {
				return self::queue_matches_state_filter($item, $state_filter);
			}));
		}
		if ($category_filter !== '') {
			$items = array_values(array_filter($items, static function ($item) use ($category_filter) {
				$categories = self::normalize_selected_categories((string) ($item->category_final ?: $item->category_proposed));
				return in_array($category_filter, $categories, true);
			}));
		}
		$items = self::sort_queue_items($items, $orderby, $order);
		$active_item_id = self::active_queue_item_id();
		$active_work_items = array_values(array_filter($items, static fn($item) => self::is_active_work_item($item, $active_item_id)));
		$ready_publish_items = array_values(array_filter($items, static fn($item) => self::is_ready_publish_item($item, $active_item_id)));
		$new_queue_items = array_values(array_filter($items, static fn($item) => self::is_new_queue_item($item, $active_item_id)));
		$rejected_items = array_values(array_filter($items, static fn($item) => in_array(EPV2_Queue::user_facing_state_for_row($item), ['rejected', 'error', 'duplicate'], true)));
		$published_items = array_values(array_filter($items, static fn($item) => (string) $item->state === 'published'));
		$published_recent_items = array_slice($published_items, 0, 12);
		$published_archive_items = array_slice($published_items, 12);
		$automation_paused = EPV2_Jobs::automation_paused();
		$collect_paused = EPV2_Jobs::collect_paused();
		$next_collect = $collect_paused ? null : EPV2_Jobs::next_collect_timestamp();
		$next_publish = $ready_publish_items !== [] ? EPV2_Queue::next_ready_publish_timestamp(false) : null;
		$deferred_publish_summary = $ready_publish_items !== [] ? EPV2_Queue::ready_publish_deferred_by_daily_limit_summary() : ['count' => 0, 'next_timestamp' => null];

		$sections = self::queue_lightweight_sections_payload(
			$active_work_items,
			$new_queue_items,
			$ready_publish_items,
			$rejected_items,
			$published_recent_items,
			$automation_paused,
			self::queue_next_publish_timestamp($ready_publish_items)
		);

		return [
			'html' => implode('', $sections),
			'sections' => $sections,
			'next_collect' => $next_collect ? (int) $next_collect : 0,
			'next_publish' => $next_publish ? (int) $next_publish : 0,
			'collect_running' => is_array($progress) && (($progress['status'] ?? '') === 'running'),
		];
	}

	private static function queue_full_blocks_html(string $orderby, string $order, string $state_filter, string $category_filter): string {
		$snapshot = self::queue_snapshot_payload($orderby, $order, $state_filter, $category_filter);
		return (string) ($snapshot['html'] ?? '');
	}

	private static function queue_blocks_html(array $active_work_items, array $new_queue_items, array $ready_publish_items, array $rejected_items, array $published_recent_items, array $published_archive_items, string $orderby, string $order, string $state_filter, string $category_filter, bool $automation_paused, ?int $next_publish, array $deferred_publish_summary = []): string {
		ob_start();
		echo '<h2 style="margin-top:18px">Новые</h2>';
		self::render_queue_block_actions($new_queue_items, 'new');
		self::render_queue_table($new_queue_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--new', 'new');
		echo '<h2 style="margin-top:24px">В работе</h2>';
		self::render_queue_block_actions($active_work_items, 'active');
		self::render_queue_table($active_work_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--live', 'active');
		$deferred_count = max(0, (int) ($deferred_publish_summary['count'] ?? 0));
		$deferred_next_timestamp = (int) ($deferred_publish_summary['next_timestamp'] ?? 0);
		$next_publish = $next_publish ?: self::fallback_next_publish_from_items($ready_publish_items, false);
		$next_publish = $next_publish ?: ($deferred_count > 0 ? null : EPV2_Jobs::next_publish_timestamp());
		if ($next_publish === null && $deferred_count <= 0) {
			$next_publish = EPV2_Jobs::next_publish_slot_after(time());
		}
		echo '<h2 style="margin-top:24px">Готово к публикации';
		if (! $automation_paused && $next_publish) {
			$publish_remaining = max(0, (int) $next_publish - time());
			$publish_minutes = (int) floor($publish_remaining / MINUTE_IN_SECONDS);
			$publish_seconds = (int) ($publish_remaining % MINUTE_IN_SECONDS);
			echo ' <span style="font-size:13px;font-weight:400;color:#50575e">до следующего слота: <strong id="epv2-next-publish-countdown" data-target="' . esc_attr((string) ($next_publish * 1000)) . '" data-interval="' . esc_attr((string) (max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS * 1000)) . '">' . esc_html(sprintf('%02d:%02d', $publish_minutes, $publish_seconds)) . '</strong></span>';
			}
		echo '</h2>';
		if ($deferred_count > 0) {
			echo '<p style="margin:0 0 8px;color:#8a6d3b">Дневным лимитом отдельно отложено: <strong>' . esc_html((string) $deferred_count) . '</strong> материал(а)';
			if ($deferred_next_timestamp > 0) {
				echo ' <span style="color:#50575e">до ' . esc_html(wp_date('H:i', $deferred_next_timestamp)) . ' по времени сайта</span>';
			}
			echo '</p>';
		}
		self::render_queue_block_actions($ready_publish_items, 'publish');
		self::render_queue_table($ready_publish_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--publish', 'publish');
		echo '<h2 style="margin-top:24px">Отклонённые</h2>';
		self::render_queue_block_actions($rejected_items, 'rejected');
		self::render_queue_table($rejected_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--rejected', 'rejected');
		echo '<h2 style="margin-top:24px">Опубликованные материалы</h2>';
		self::render_queue_block_actions($published_recent_items, 'published');
		self::render_queue_table($published_recent_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--published', 'published');
		if ($published_archive_items !== []) {
			echo '<details class="epv2-queue-archive" style="margin-top:16px"><summary style="cursor:pointer;font-weight:600">Архив опубликованных материалов (' . count($published_archive_items) . ')</summary>';
			self::render_queue_block_actions($published_archive_items, 'archive');
			self::render_queue_table($published_archive_items, $orderby, $order, $state_filter, $category_filter, 'epv2-queue-table-wrap epv2-queue-table-wrap--archive', 'archive');
			echo '</details>';
		}
		return (string) ob_get_clean();
	}

	private static function render_queue_block_actions(array $items, string $table_type): void {
		$ids = array_values(array_filter(array_map(static fn($item) => (int) ($item->id ?? 0), $items)));
		echo '<div style="margin:0 0 8px">';
		if ($ids !== []) {
			$input_id = 'epv2-bulk-delete-ids-' . sanitize_html_class($table_type);
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
			wp_nonce_field('epv2_delete_queue_items');
			echo '<input type="hidden" name="action" value="epv2_delete_queue_items">';
			echo '<input type="hidden" name="ids_csv" id="' . esc_attr($input_id) . '" value="">';
			echo '<button class="button button-secondary" type="submit" onclick="var ids=[...document.querySelectorAll(\'.epv2-queue-check[data-block=&quot;' . esc_attr($table_type) . '&quot;]:checked\')].map(function(cb){return cb.value;}); if(!ids.length){alert(\'Выберите материалы в этом блоке\'); return false;} document.getElementById(\'' . esc_js($input_id) . '\').value=ids.join(\',\'); return confirm(\'Удалить выбранные материалы из этого блока?\');">Удалить выбранные</button>';
			echo '</form>';
		}
		if ($table_type === 'rejected') {
			echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_clear_rejected_queue'), 'epv2_clear_rejected_queue')) . '" onclick="return confirm(\'Очистить все отклонённые материалы?\')">Очистить</a>';
		}
		echo '</div>';
	}

	private static function queue_lightweight_blocks_html(): string {
		$active_id = self::active_queue_item_id();
		$active_items = $active_id > 0 ? self::queue_light_rows_by_ids([$active_id]) : [];
		$new_items = self::queue_light_rows_by_states(['new', 'retry_process', 'ready_review', 'reserve', 'processing_de'], 30, [$active_id]);
		$publish_items = self::queue_light_rows_by_states(['ready_publish', 'retry_publish', 'publishing'], 30);
		$rejected_items = self::queue_light_rows_by_states(['rejected', 'error', 'duplicate'], 30);
		$published_items = self::queue_light_rows_by_states(['published'], 30);
		$automation_paused = EPV2_Jobs::automation_paused();
		$next_publish = self::queue_next_publish_timestamp($publish_items);

		ob_start();
		self::render_light_queue_section('Новые', $new_items, 'new');
		self::render_light_queue_section('В работе', $active_items, 'active');
		self::render_light_queue_section('Готово к публикации', $publish_items, 'publish', [
			'automation_paused' => $automation_paused,
			'next_publish' => $next_publish,
		]);
		self::render_light_queue_section('Отклонённые', $rejected_items, 'rejected');
		self::render_light_queue_section('Опубликованные материалы', $published_items, 'published');
		return (string) ob_get_clean();
	}

	private static function queue_lightweight_sections_payload(array $active_items, array $new_items, array $publish_items, array $rejected_items, array $published_items, bool $automation_paused, int $next_publish): array {
		$sections = [];

		ob_start();
		self::render_light_queue_section('Новые', $new_items, 'new');
		$sections['new'] = (string) ob_get_clean();

		ob_start();
		self::render_light_queue_section('В работе', $active_items, 'active');
		$sections['active'] = (string) ob_get_clean();

		ob_start();
		self::render_light_queue_section('Готово к публикации', $publish_items, 'publish', [
			'automation_paused' => $automation_paused,
			'next_publish' => $next_publish,
		]);
		$sections['publish'] = (string) ob_get_clean();

		ob_start();
		self::render_light_queue_section('Отклонённые', $rejected_items, 'rejected');
		$sections['rejected'] = (string) ob_get_clean();

		ob_start();
		self::render_light_queue_section('Опубликованные материалы', $published_items, 'published');
		$sections['published'] = (string) ob_get_clean();

		return $sections;
	}

	private static function render_light_queue_section(string $title, array $items, string $table_type, array $context = []): void {
		echo '<section id="epv2-queue-section-' . esc_attr($table_type) . '" data-queue-section="' . esc_attr($table_type) . '">';
		echo '<h2 style="margin-top:24px">';
		echo esc_html($title);
		if ($table_type === 'publish') {
			$automation_paused = ! empty($context['automation_paused']);
			$next_publish = (int) ($context['next_publish'] ?? 0);
			if ($next_publish > 0) {
				$publish_remaining = max(0, $next_publish - time());
				$publish_minutes = (int) floor($publish_remaining / MINUTE_IN_SECONDS);
				$publish_seconds = (int) ($publish_remaining % MINUTE_IN_SECONDS);
				echo ' <span style="font-size:13px;font-weight:400;color:#50575e">до следующего слота: <strong id="epv2-next-publish-countdown" data-target="' . esc_attr((string) ($next_publish * 1000)) . '" data-interval="' . esc_attr((string) (max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS * 1000)) . '">' . esc_html(sprintf('%02d:%02d', $publish_minutes, $publish_seconds)) . '</strong></span>';
				if ($automation_paused) {
					echo ' <span style="font-size:12px;font-weight:400;color:#8a6d3b">(автопилот на паузе)</span>';
				}
			}
		}
		echo '</h2>';
		self::render_queue_block_actions($items, $table_type);
		self::render_light_queue_table($items, $table_type);
		echo '</section>';
	}

	private static function render_light_queue_table(array $items, string $table_type): void {
		$orderby = sanitize_key((string) ($_GET['orderby'] ?? 'updated_at'));
		$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
		$state_filter = sanitize_key((string) ($_GET['state_filter'] ?? ''));
		$category_filter = sanitize_title((string) ($_GET['category_filter'] ?? ''));
		$time_sort = $table_type === 'published' ? 'published_at' : ($table_type === 'publish' ? 'ready_publish_at' : 'updated_at');
		echo '<div class="epv2-queue-table-wrap" style="max-height:420px;overflow:auto;border:1px solid #dcdcde;border-radius:8px;background:#fff">';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th><input type="checkbox" onclick="document.querySelectorAll(\'.epv2-queue-check[data-block=&quot;' . esc_attr($table_type) . '&quot;]\').forEach(cb => cb.checked = this.checked)"></th>';
		echo '<th>' . self::queue_sort_link('id', 'ID', $orderby, $order, $state_filter, $category_filter) . '</th>';
		echo '<th>' . self::queue_sort_link('state', 'Статус', $orderby, $order, $state_filter, $category_filter) . '</th>';
		echo '<th>' . self::queue_sort_link('priority', 'Приоритет', $orderby, $order, $state_filter, $category_filter) . '</th>';
		echo '<th>' . self::queue_sort_link('quality', 'Качество', $orderby, $order, $state_filter, $category_filter) . '</th>';
		echo '<th>' . self::queue_sort_link('seo', 'SEO', $orderby, $order, $state_filter, $category_filter) . '</th>';
		echo '<th>Готовность</th><th>Медиа</th><th>Что не ок</th><th>Заголовок</th>';
		echo '<th>' . self::queue_sort_link('category', 'Категории', $orderby, $order, $state_filter, $category_filter) . '</th>';
		echo '<th>URL</th><th>' . self::queue_sort_link('created_at', 'Создано', $orderby, $order, $state_filter, $category_filter) . '</th>';
		echo '<th>' . self::queue_sort_link($time_sort, $table_type === 'publish' ? 'Готово с' : ($table_type === 'published' ? 'Опубликовано' : 'Обновлено'), $orderby, $order, $state_filter, $category_filter) . '</th><th>Действия</th>';
		echo '</tr></thead><tbody>';
		if ($items === []) {
			echo '<tr><td colspan="15" style="color:#646970">Нет материалов.</td></tr>';
		}
		foreach ($items as $item) {
			$categories = self::normalize_selected_categories((string) ($item->category_final ?: $item->category_proposed));
			$category_label = $categories !== [] ? implode(', ', array_map([self::class, 'category_label'], $categories)) : '—';
			echo '<tr>';
			echo '<td><input class="epv2-queue-check" data-block="' . esc_attr($table_type) . '" type="checkbox" name="ids[]" value="' . (int) $item->id . '"></td>';
			echo '<td>' . (int) $item->id . '</td>';
			echo '<td>' . esc_html(self::queue_light_state_label($item)) . '</td>';
			echo '<td>' . self::queue_light_priority_badge($item) . '</td>';
			echo '<td>' . self::queue_light_metric_badge(self::queue_light_quality_score($item), 'quality') . '</td>';
			echo '<td>' . self::queue_light_metric_badge(self::queue_light_seo_score($item), 'seo') . '</td>';
			echo '<td>' . self::queue_light_release_badge(self::queue_light_release_score($item), self::queue_light_google_score($item)) . '</td>';
			echo '<td>' . self::queue_light_media_summary($item) . '</td>';
			echo '<td>' . self::queue_light_issue_summary(self::queue_light_issue_list($item)) . '</td>';
			echo '<td>' . esc_html(wp_trim_words((string) ($item->original_title ?? ''), 12, '')) . '</td>';
			echo '<td>' . esc_html($category_label) . '</td>';
			echo '<td>' . ((string) ($item->original_url ?? '') !== '' ? '<a href="' . esc_url((string) $item->original_url) . '" target="_blank" rel="noopener">Открыть</a>' : '<span style="color:#8c8f94">—</span>') . '</td>';
			echo '<td>' . esc_html(self::queue_site_datetime((string) ($item->created_at ?? ''))) . '</td>';
			echo '<td>' . esc_html($table_type === 'publish' ? self::queue_ready_publish_at($item) : self::queue_site_datetime((string) ($item->updated_at ?? ''))) . '</td>';
			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=epv2-review&item=' . (int) $item->id)) . '">Проверить</a> ';
			if ((string) ($item->state ?? '') === 'ready_review') {
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_queue_to_publish&id=' . (int) $item->id), 'epv2_queue_to_publish_' . (int) $item->id)) . '">Готово к публикации</a> ';
			}
			if (in_array((string) ($item->state ?? ''), ['ready_publish', 'retry_publish', 'ready_review'], true)) {
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_publish_now&id=' . (int) $item->id), 'epv2_publish_now_' . (int) $item->id)) . '">Опубликовать</a> ';
			}
			if ((string) ($item->state ?? '') === 'published') {
				$edit_url = self::queue_post_edit_url($item);
				if ($edit_url !== '') {
					echo '<a class="button button-small" href="' . esc_url($edit_url) . '">Редактировать пост</a> ';
				}
			}
			echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_delete_queue_item&id=' . (int) $item->id), 'epv2_delete_queue_item_' . (int) $item->id)) . '" onclick="return confirm(\'Удалить этот материал?\')">Удалить</a>';
			echo '<div style="margin-top:6px;color:#646970;font-size:12px">' . esc_html(self::queue_action_hint(EPV2_Queue::user_facing_state_for_row($item), $item)) . '</div>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function queue_light_rows_by_ids(array $ids): array {
		$ids = array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
		if ($ids === []) {
			return [];
		}
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		return $wpdb->get_results($wpdb->prepare(
			"SELECT id, state, original_title, category_proposed, category_final, original_url, source_image_url, created_at, updated_at, admin_notes, error_message, publish_payload, post_id,
				CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$.selection.score')) AS UNSIGNED) AS _epv2_selection_score,
				JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$.selection.tier')) AS _epv2_selection_tier,
				CAST(JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.quality.score')) AS UNSIGNED) AS _epv2_quality_score,
				CAST(JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.seo_quality.score')) AS UNSIGNED) AS _epv2_seo_score,
				CAST(JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.release_quality.score')) AS UNSIGNED) AS _epv2_release_score,
				CAST(JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.google_quality.score')) AS UNSIGNED) AS _epv2_google_score,
				JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$.featured_media_url')) AS _epv2_featured_media_url,
				JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$.media_url')) AS _epv2_media_url,
				JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$.languages.de.media_url')) AS _epv2_de_media_url
			FROM {$table}
			WHERE id IN ({$placeholders})
			ORDER BY updated_at DESC, id DESC",
			...$ids
		));
	}

	private static function queue_light_rows_by_states(array $states, int $limit = 30, array $exclude_ids = []): array {
		$states = array_values(array_filter(array_map('sanitize_text_field', $states)));
		if ($states === []) {
			return [];
		}
		$limit = max(1, min(100, $limit));
		$exclude_ids = array_values(array_filter(array_map('intval', $exclude_ids), static fn($id) => $id > 0));
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$state_placeholders = implode(',', array_fill(0, count($states), '%s'));
		$sql = "SELECT id, state, original_title, category_proposed, category_final, original_url, source_image_url, created_at, updated_at, admin_notes, error_message, publish_payload, post_id,
			CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$.selection.score')) AS UNSIGNED) AS _epv2_selection_score,
			JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$.selection.tier')) AS _epv2_selection_tier,
			CAST(JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.quality.score')) AS UNSIGNED) AS _epv2_quality_score,
			CAST(JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.seo_quality.score')) AS UNSIGNED) AS _epv2_seo_score,
			CAST(JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.release_quality.score')) AS UNSIGNED) AS _epv2_release_score,
			CAST(JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.google_quality.score')) AS UNSIGNED) AS _epv2_google_score,
			JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$.featured_media_url')) AS _epv2_featured_media_url,
			JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$.media_url')) AS _epv2_media_url,
			JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$.languages.de.media_url')) AS _epv2_de_media_url
			FROM {$table}
			WHERE state IN ({$state_placeholders})";
		$args = $states;
		if ($exclude_ids !== []) {
			$id_placeholders = implode(',', array_fill(0, count($exclude_ids), '%d'));
			$sql .= " AND id NOT IN ({$id_placeholders})";
			$args = array_merge($args, $exclude_ids);
		}
		$sql .= ' ORDER BY ' . self::queue_light_sql_order_clause() . ' LIMIT %d';
		$args[] = $limit;
		return $wpdb->get_results($wpdb->prepare($sql, ...$args));
	}

	private static function queue_light_sql_order_clause(): string {
		$orderby = sanitize_key((string) ($_GET['orderby'] ?? 'updated_at'));
		$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
		$column = match ($orderby) {
			'id' => 'id',
			'state' => 'state',
			'priority' => "_epv2_selection_score",
			'quality' => "_epv2_quality_score",
			'seo' => "_epv2_seo_score",
			'category' => 'category_final',
			'created_at' => 'created_at',
			'published_at', 'ready_publish_at', 'updated_at' => 'updated_at',
			default => 'updated_at',
		};
		return $column . ' ' . $order . ', id ' . $order;
	}

	private static function queue_light_state_label(object $item): string {
		$item_id = (int) ($item->id ?? 0);
		$active_id = self::active_queue_item_id();
			$notes = self::queue_item_notes($item);
			$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$state = sanitize_key((string) ($item->state ?? ''));
			$user_state = EPV2_Queue::user_facing_state_for_row($item);
			if (in_array($user_state, ['ready_publish', 'retry_publish'], true)) {
				$deferred_label = self::queue_deferred_publish_label($item, $system);
				if ($deferred_label !== '') {
					return $deferred_label;
				}
				$retry_after = trim((string) ($system['retry_after'] ?? ''));
				if ($retry_after !== '') {
					$retry_ts = strtotime($retry_after);
					if ($retry_ts) {
						return 'Готов к публикации до ' . wp_date('H:i', $retry_ts);
					}
				}
				return 'Готов к публикации';
			}
			if ($item_id > 0 && $item_id === $active_id) {
				if (self::queue_light_item_is_stalled($item, $system)) {
					return 'Остановлено защитой · 0% · обработка прервана';
				}
			return sprintf(
				'В работе · %d%% · %s',
				self::queue_light_progress_percent($item, $system),
					self::queue_light_progress_stage_label($item, $system)
				);
			}
			if ($state === 'publishing') {
				return 'Публикуется';
			}
			if ($state === 'new') {
				$retry_after = trim((string) ($system['retry_after'] ?? ''));
				$retry_ts = $retry_after !== '' ? strtotime($retry_after) : false;
				if ($retry_ts && $retry_ts > time()) {
					return 'Пауза доводки до ' . wp_date('H:i', $retry_ts);
				}
			}
		return match ($state) {
			'processing_de', 'new', 'retry_process', 'ready_review', 'reserve' => 'Новый',
			'published' => 'Опубликован',
			'rejected' => 'Отклонён',
			'duplicate' => 'Дубликат',
			'error' => 'Ошибка',
			default => $state !== '' ? $state : '—',
		};
	}

	private static function queue_item_notes(object $item): array {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		return is_array($notes) ? $notes : [];
	}

	private static function queue_light_priority_badge(object $item): string {
		$score = (int) ($item->_epv2_selection_score ?? 0);
		$tier = trim((string) ($item->_epv2_selection_tier ?? ''));
		if ($score <= 0 && $tier === '') {
			$analysis = self::queue_analysis($item);
			$score = (int) ($analysis['score'] ?? ($item->story_score ?? 0));
			$tier = trim((string) ($analysis['tier'] ?? ''));
		}
		if ($score <= 0 && $tier === '') {
			return '<span style="color:#8c8f94">—</span>';
		}
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
		return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:11px;font-weight:700">' . esc_html(($tier !== '' ? $tier : '—') . ' / ' . $score) . '</span>';
	}

	private static function queue_light_quality_score(object $item): int {
		return self::queue_light_payload_score($item, '_epv2_quality_score', 'quality');
	}

	private static function queue_light_seo_score(object $item): int {
		return self::queue_light_payload_score($item, '_epv2_seo_score', 'seo_quality');
	}

	private static function queue_light_release_score(object $item): int {
		return self::queue_light_payload_score($item, '_epv2_release_score', 'release_quality');
	}

	private static function queue_light_google_score(object $item): int {
		return self::queue_light_payload_score($item, '_epv2_google_score', 'google_quality');
	}

	private static function queue_light_payload_score(object $item, string $property, string $meta_key): int {
		if (isset($item->{$property}) && is_numeric($item->{$property})) {
			return (int) $item->{$property};
		}
		$payload = self::queue_cached_payload($item);
		return (int) ($payload['_meta'][$meta_key]['score'] ?? 0);
	}

	private static function queue_light_metric_badge(int $score, string $type): string {
		if ($score <= 0) {
			return '<span style="color:#8c8f94">—</span>';
		}
		if ($type === 'seo') {
			$bg = $score >= 90 ? '#dbeafe' : ($score >= 78 ? '#e0f2fe' : '#fee2e2');
			$color = $score >= 90 ? '#1d4ed8' : ($score >= 78 ? '#075985' : '#991b1b');
		} else {
			$bg = $score >= 90 ? '#dcfce7' : ($score >= 80 ? '#fef3c7' : '#fee2e2');
			$color = $score >= 90 ? '#166534' : ($score >= 80 ? '#92400e' : '#991b1b');
		}
		return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:11px;font-weight:700">' . esc_html((string) $score) . '</span>';
	}

	private static function queue_light_release_badge(int $release_score, int $google_score): string {
		if ($release_score <= 0 && $google_score <= 0) {
			return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:11px;font-weight:700">0 / 0</span>';
		}
		$bg = ($release_score >= 90 && $google_score >= 90) ? '#dcfce7' : (($release_score >= 80 && $google_score >= 80) ? '#fef3c7' : '#fee2e2');
		$color = ($release_score >= 90 && $google_score >= 90) ? '#166534' : (($release_score >= 80 && $google_score >= 80) ? '#92400e' : '#991b1b');
		return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:11px;font-weight:700">' . esc_html($release_score . ' / ' . $google_score) . '</span>';
	}

	private static function queue_light_media_summary(object $item): string {
		$info = self::queue_light_media_info($item);
		$provider = (string) ($info['provider'] ?? '');
		$url = (string) ($info['url'] ?? '');
		$label = match ($provider) {
			'pexels_risk' => 'Pexels риск',
			'pexels' => 'Pexels',
			'wikimedia' => 'Wikimedia',
			'generated' => 'Generated',
			'source' => 'Source',
			default => $url !== '' ? 'External' : 'Нет',
		};
		$bg = match ($provider) {
			'source' => '#dcfce7',
			'wikimedia', 'generated' => '#e0f2fe',
			'pexels_risk' => '#fee2e2',
			'pexels' => '#fef3c7',
			default => $url !== '' ? '#f3f4f6' : '#fee2e2',
		};
		$color = match ($provider) {
			'source' => '#166534',
			'wikimedia', 'generated' => '#075985',
			'pexels_risk' => '#991b1b',
			'pexels' => '#92400e',
			default => $url !== '' ? '#374151' : '#991b1b',
		};
		$title = implode(' | ', array_filter([
			(string) ($info['reason'] ?? ''),
			$url,
			(string) ($info['source_image_url'] ?? ''),
		]));
		return '<span title="' . esc_attr($title) . '" style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:11px;font-weight:700">' . esc_html($label) . '</span>';
	}

	private static function queue_light_media_info(object $item): array {
		$url = esc_url_raw((string) (
			$item->_epv2_featured_media_url
			?? $item->_epv2_media_url
			?? $item->_epv2_de_media_url
			?? ''
		));
		$source_image_url = esc_url_raw((string) ($item->source_image_url ?? ''));
		if ($url === '' && $source_image_url !== '') {
			$url = $source_image_url;
		}
		if ($url === '') {
			return [
				'provider' => '',
				'url' => '',
				'source_image_url' => $source_image_url,
				'reason' => 'featured media отсутствует',
			];
		}
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$provider = 'external';
		$reason = $host !== '' ? $host : $url;
		if (class_exists('EPV2_Media') && EPV2_Media::is_generated_story_cover_url($url)) {
			$provider = 'generated';
			$reason = 'сгенерированная обложка';
		} elseif (str_contains($host, 'pexels.com')) {
			$provider = self::queue_light_media_is_high_context($item) ? 'pexels_risk' : 'pexels';
			$reason = $provider === 'pexels_risk' ? 'Pexels fallback для high-context темы' : 'Pexels fallback';
		} elseif (str_contains($host, 'wikimedia.org')) {
			$provider = 'wikimedia';
			$reason = 'Wikimedia fallback';
		} elseif ($source_image_url !== '' && self::queue_light_same_host($url, $source_image_url)) {
			$provider = 'source';
			$reason = 'source-first image';
		}
		return [
			'provider' => $provider,
			'url' => $url,
			'source_image_url' => $source_image_url,
			'reason' => $reason,
		];
	}

	private static function queue_light_media_is_high_context(object $item): bool {
		$category = sanitize_key((string) ($item->category_final ?: $item->category_proposed));
		if (! in_array($category, ['politik', 'deutschland', 'welt', 'ukraine', 'europa', 'bayern', 'muenchen'], true)) {
			return false;
		}
		$text = mb_strtolower(trim(wp_strip_all_tags((string) ($item->original_title ?? '') . ' ' . (string) ($item->original_excerpt ?? ''))));
		return preg_match('/\b(merz|cdu|csu|spd|afd|fdp|rente|renten|bundesregierung|bundestag|kanzler|minister|regierung|wahl|migration|asyl|ukraine|israel|iran|gaza|krieg|war)\b/u', $text) === 1;
	}

	private static function queue_light_same_host(string $left, string $right): bool {
		$left_host = preg_replace('/^www\./i', '', mb_strtolower((string) wp_parse_url($left, PHP_URL_HOST)));
		$right_host = preg_replace('/^www\./i', '', mb_strtolower((string) wp_parse_url($right, PHP_URL_HOST)));
		return $left_host !== '' && $left_host === $right_host;
	}

	private static function queue_light_issue_list(object $item): array {
		$issues = [];
		$error = trim((string) ($item->error_message ?? ''));
		if ($error !== '') {
			$issues[] = $error;
		}
		$quality_score = self::queue_light_quality_score($item);
		$seo_score = self::queue_light_seo_score($item);
		$release_score = self::queue_light_release_score($item);
		$google_score = self::queue_light_google_score($item);
		if ($quality_score > 0 && $quality_score < 90) {
			$issues[] = 'редакционное качество ниже нормы';
		}
		if ($seo_score > 0 && $seo_score < 90) {
			$issues[] = 'SEO требует доводки';
		}
		if ($release_score > 0 && $release_score < 90) {
			$issues[] = 'готовность к выпуску не дотянута';
		} elseif ($release_score <= 0 && ($quality_score > 0 || $seo_score > 0)) {
			$issues[] = 'готовность к выпуску не подтверждена';
		}
		if ($google_score > 0 && $google_score < 90) {
			$issues[] = 'Google preflight не пройден';
		} elseif ($google_score <= 0 && ($quality_score > 0 || $seo_score > 0)) {
			$issues[] = 'Google preflight не подтверждён';
		}
		$media_info = self::queue_light_media_info($item);
		if (($media_info['provider'] ?? '') === 'pexels_risk') {
			$issues[] = 'медиа: Pexels fallback для high-context темы';
		} elseif (($media_info['url'] ?? '') === '' && ! in_array((string) ($item->state ?? ''), ['new', 'rejected', 'duplicate'], true)) {
			$issues[] = 'медиа: нет featured image';
		}
		return array_values(array_unique($issues));
	}

	private static function queue_light_item_is_stalled(object $item, array $system): bool {
		$item_id = (int) ($item->id ?? 0);
		if ($item_id <= 0 || $item_id !== self::active_queue_item_id()) {
			return false;
		}
		if (! EPV2_Jobs::automation_paused()) {
			return false;
		}
		$state = sanitize_key((string) ($item->state ?? ''));
		$step_status = sanitize_key((string) ($system['workflow_step_status'] ?? ''));
		$workflow_step = sanitize_key((string) ($system['workflow_step'] ?? ''));
		$heartbeat_at = strtotime((string) ($system['workflow_heartbeat_at'] ?? '')) ?: 0;
		$claimed_at = strtotime((string) ($system['workflow_claimed_at'] ?? '')) ?: 0;
		$updated_at = strtotime((string) ($item->updated_at ?? '')) ?: 0;
		$fresh_until = max($heartbeat_at, $claimed_at, $updated_at);

		if (in_array($step_status, ['claimed', 'started'], true) && $fresh_until > 0 && $fresh_until <= (time() - 300)) {
			return true;
		}

		return $state === 'new' && in_array($workflow_step, ['publish_ready_gate', 'publish_finish', ''], true);
	}

	private static function queue_light_progress_percent(object $item, array $system): int {
		if (self::queue_light_item_is_stalled($item, $system)) {
			return 0;
		}
		$state = sanitize_key((string) ($item->state ?? ''));
		if (in_array($state, ['publishing', 'published'], true)) {
			return 100;
		}
		if (in_array($state, ['ready_publish', 'retry_publish'], true)) {
			return 95;
		}
		$step = sanitize_key((string) ($system['workflow_step'] ?? ''));
		$status = sanitize_key((string) ($system['workflow_step_status'] ?? ''));
		$map = [
			'claimed' => 10,
			'build_de_master' => 25,
			'translate_uk' => 50,
			'translate_en' => 70,
			'publish_ready_gate' => 90,
			'initial_analysis' => 20,
			'context_analysis' => 35,
			'dossier' => 50,
			'de_master' => 65,
			'translations' => 80,
			'publish_finish' => 90,
			'ready_publish' => 95,
		];
		$percent = (int) ($map[$step] ?? ($state === 'new' ? 0 : 15));
		if (in_array($status, ['done', 'finished', 'completed'], true)) {
			$percent += 5;
		}
		return max(0, min(95, $percent));
	}

	private static function queue_light_progress_stage_label(object $item, array $system): string {
		if (self::queue_light_item_is_stalled($item, $system)) {
			return 'обработка прервана';
		}
		$state = sanitize_key((string) ($item->state ?? ''));
		if ($state === 'publishing') {
			return 'публикует на сайт';
		}
		if (in_array($state, ['ready_publish', 'retry_publish'], true)) {
			$deferred_label = self::queue_deferred_publish_label($item, $system, false);
			return $deferred_label !== '' ? mb_strtolower($deferred_label) : 'ждёт слот публикации';
		}
		$attempt_label = self::queue_finish_attempt_label($system);
		$live_status = trim((string) ($system['live_status'] ?? ''));
		if ($live_status !== '') {
			return mb_strtolower($live_status . $attempt_label);
		}
		return match (sanitize_key((string) ($system['workflow_step'] ?? ''))) {
			'claimed' => 'материал взят в работу',
			'build_de_master' => 'собирает DE master',
			'translate_uk' => 'готовит украинскую версию',
			'translate_en' => 'готовит английскую версию',
			'publish_ready_gate' => 'финальная publish-ready проверка' . $attempt_label,
			'initial_analysis' => 'анализирует материал',
			'context_analysis' => 'собирает фактуру',
			'dossier' => 'готовит досье',
			'de_master' => 'пишет DE master',
			'translations' => 'готовит переводы',
			'publish_finish' => 'собирает пакет публикации' . $attempt_label,
			'ready_publish' => 'ждёт слот публикации',
			default => 'обрабатывает материал',
		};
	}

	private static function fallback_next_publish_from_items(array $items, bool $includeDeferredByDailyLimit = true): ?int {
		$candidate = 0;
		foreach ($items as $item) {
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			if (
				! $includeDeferredByDailyLimit
				&& ! empty($notes['_system']['publish_deferred_by_daily_limit'])
			) {
				continue;
			}
			$not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
			if ($not_before <= 0) {
				continue;
			}
			if ($candidate <= 0 || $not_before < $candidate) {
				$candidate = $not_before;
			}
		}
		return $candidate > 0 ? $candidate : null;
	}

	private static function queue_next_publish_timestamp(array $items): int {
		$next_publish = (int) (self::fallback_next_publish_from_items($items, false) ?: 0);
		return max(0, $next_publish);
	}

	private static function active_queue_item_id(): int {
		return (int) get_option('epv2_active_automation_item', 0);
	}

	private static function orchestrator_v2_ui_enabled(): bool {
		return EPV2_Jobs::orchestrator_v2_enabled();
	}

	private static function is_active_work_item(object $item, int $active_item_id): bool {
		return EPV2_Queue::user_facing_state_for_row($item) === 'active';
	}

	private static function is_new_queue_item(object $item, int $active_item_id): bool {
		return EPV2_Queue::user_facing_state_for_row($item) === 'new';
	}

	private static function is_manual_confirmation_item(object $item, int $active_item_id): bool {
		if ((int) ($item->id ?? 0) === $active_item_id && ! self::item_requires_manual_confirmation($item)) {
			return false;
		}
		return self::item_requires_manual_confirmation($item);
	}

	private static function item_requires_manual_confirmation(object $item): bool {
		if ((string) ($item->state ?? '') !== 'ready_review') {
			return false;
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		if (($notes['_system']['manual_confirmation_required'] ?? '') === 'media') {
			$payload = self::queue_cached_payload($item);
			$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$dossier = is_array($meta['source_dossier'] ?? null) ? $meta['source_dossier'] : [];
			$source_count = (int) ($meta['source_count'] ?? 0);
			$supporting_count = count((array) ($dossier['supporting'] ?? []));
			$used_search = ! empty($dossier['used_search']);
			return $source_count >= 2 && $supporting_count >= 1 && $used_search;
		}
		$error = mb_strtolower((string) ($item->error_message ?? ''));
		return preg_match('/требует ручного подтверждения media/u', $error) === 1;
	}

	private static function is_ready_publish_item(object $item, int $active_item_id): bool {
		return in_array(EPV2_Queue::user_facing_state_for_row($item), ['ready_publish', 'publishing'], true);
	}

	private static function is_recoverable_queue_item(object $item): bool {
		return in_array(EPV2_Queue::user_facing_state_for_row($item), ['new', 'active', 'ready_publish', 'publishing'], true);
	}

	private static function queue_matches_state_filter(object $item, string $state_filter): bool {
		if ($state_filter === '') {
			return true;
		}
		return EPV2_Queue::user_facing_state_for_row($item) === $state_filter;
	}

	private static function render_queue_table(array $items, string $orderby, string $order, string $state_filter, string $category_filter, string $wrap_class = 'epv2-queue-table-wrap', string $table_type = 'default'): void {
		$show_ready_publish_at = $table_type === 'publish';
		$is_published_block = in_array($table_type, ['published', 'archive'], true);
		$show_post_column = $is_published_block;
		$show_time_column = ! $show_ready_publish_at || $is_published_block;
		$column_count = 12 + ($show_ready_publish_at ? 1 : 0) + ($show_time_column ? 1 : 0) + ($show_post_column ? 1 : 0);
		$time_column_label = $is_published_block
			? self::queue_sort_link('published_at', 'Опубликовано', $orderby, $order, $state_filter, $category_filter)
			: 'Обновлено';
		echo '<div class="' . esc_attr($wrap_class) . '" style="max-height:420px;overflow:auto;border:1px solid #dcdcde;border-radius:8px;background:#fff">';
		echo '<table class="widefat striped"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll(\'.epv2-queue-check[data-block=&quot;' . esc_attr($table_type) . '&quot;]\').forEach(cb => cb.checked = this.checked)"></th><th>' . self::queue_sort_link('id', 'ID', $orderby, $order, $state_filter, $category_filter) . '</th><th>' . self::queue_sort_link('state', 'Статус', $orderby, $order, $state_filter, $category_filter) . '</th><th>' . self::queue_sort_link('priority', 'Приоритет', $orderby, $order, $state_filter, $category_filter) . '</th><th>' . self::queue_sort_link('quality', 'Качество', $orderby, $order, $state_filter, $category_filter) . '</th><th>' . self::queue_sort_link('seo', 'SEO', $orderby, $order, $state_filter, $category_filter) . '</th><th>Метки</th><th>Заголовок</th><th>' . self::queue_sort_link('category', 'Категории', $orderby, $order, $state_filter, $category_filter) . '</th><th>URL</th><th>' . self::queue_sort_link('created_at', 'Создано', $orderby, $order, $state_filter, $category_filter) . '</th>' . ($show_ready_publish_at ? '<th>Готово с</th>' : '') . ($show_time_column ? '<th>' . $time_column_label . '</th>' : '') . ($show_post_column ? '<th>Пост</th>' : '') . '<th>Действия</th></tr></thead><tbody>';
		if ($items === []) {
			echo '<tr><td colspan="' . esc_attr((string) $column_count) . '" style="color:#646970">Нет материалов.</td></tr>';
		}
		foreach ($items as $item) {
				echo '<tr><td><input class="epv2-queue-check" data-block="' . esc_attr($table_type) . '" type="checkbox" name="ids[]" value="' . (int) $item->id . '"></td><td>' . (int) $item->id . '</td><td>' . esc_html(self::queue_state_label($item)) . '</td><td>' . self::queue_score_badge($item) . '</td><td>' . self::queue_quality_badge($item) . '</td><td>' . self::queue_seo_badge($item) . '</td><td>' . self::queue_badges($item) . '</td><td>' . esc_html(wp_trim_words((string) $item->original_title, 12, '')) . '</td><td>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
			wp_nonce_field('epv2_update_queue_category_' . (int) $item->id);
			echo '<input type="hidden" name="action" value="epv2_update_queue_category">';
			echo '<input type="hidden" name="id" value="' . (int) $item->id . '">';
			echo self::category_checkboxes('categories', self::normalize_selected_categories((string) ($item->category_final ?: $item->category_proposed)), 'epv2-cat-group-' . (int) $item->id, true, true);
			echo '<script>document.querySelectorAll(".epv2-cat-group-' . (int) $item->id . ' input[type=checkbox]").forEach(function(cb){cb.addEventListener("change",function(){var boxes=[...document.querySelectorAll(".epv2-cat-group-' . (int) $item->id . ' input[type=checkbox]:checked")]; if(boxes.length>3){ this.checked=false; return; } this.form.submit();});});</script>';
			echo '</form>';
			echo '</td><td><a href="' . esc_url($item->original_url) . '" target="_blank" rel="noopener">Открыть</a></td><td>' . esc_html(self::queue_site_datetime((string) ($item->created_at ?? ''))) . '</td>';
			if ($show_ready_publish_at) {
				echo '<td>' . esc_html(self::queue_ready_publish_at($item)) . '</td>';
			}
			if ($show_time_column) {
				if ($is_published_block) {
					$time_value = self::queue_published_at($item);
				} else {
					$time_value = self::queue_updated_at($item);
				}
				echo '<td>' . esc_html($time_value) . '</td>';
			}
			if ($show_post_column) {
				echo '<td>' . self::queue_post_link($item) . '</td>';
			}
			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=epv2-review&item=' . (int) $item->id)) . '">Проверить</a> ';
			if ($item->state === 'ready_review') {
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_queue_to_publish&id=' . (int) $item->id), 'epv2_queue_to_publish_' . (int) $item->id)) . '">Готово к публикации</a> ';
			}
			if (in_array($item->state, ['ready_publish', 'ready_review'], true)) {
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_publish_now&id=' . (int) $item->id), 'epv2_publish_now_' . (int) $item->id)) . '">Опубликовать</a> ';
			}
			if ($is_published_block) {
				$edit_url = self::queue_post_edit_url($item);
				if ($edit_url !== '') {
					echo '<a class="button button-small" href="' . esc_url($edit_url) . '">Редактировать пост</a> ';
				}
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_delete_published_post&id=' . (int) $item->id), 'epv2_delete_published_post_' . (int) $item->id)) . '" onclick="return confirm(\'Удалить опубликованную новость с сайта?\')">Удалить новость</a> ';
			} else {
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_delete_queue_item&id=' . (int) $item->id), 'epv2_delete_queue_item_' . (int) $item->id)) . '" onclick="return confirm(\'Удалить этот материал?\')">Удалить</a> ';
			}
			echo '<div style="margin-top:6px;color:#646970;font-size:12px">' . esc_html(self::queue_action_hint(EPV2_Queue::user_facing_state_for_row($item), $item)) . '</div>';
			echo '</td></tr>';
			}
		echo '</tbody></table></div>';
	}

	private static function queue_ready_publish_at(object $item): string {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$ready_at = (string) ($notes['_system']['ready_publish_at'] ?? '');
		if ($ready_at !== '') {
			return self::queue_site_datetime($ready_at);
		}
		return (string) ($item->state === 'ready_publish' ? self::queue_site_datetime((string) ($item->updated_at ?? '')) : '');
	}

	private static function queue_updated_at(object $item): string {
		return self::queue_site_datetime((string) ($item->updated_at ?? ''));
	}

	public static function review(): void {
		$item_id = (int) ($_GET['item'] ?? 0);
		$item = $item_id > 0 ? EPV2_Queue::get_item($item_id) : null;
		echo '<div class="wrap"><h1>Проверка материала</h1>';
		if (! $item) {
			echo '<p>Материал не найден.</p></div>';
			return;
		}

		$payload = EPV2_Review::ensure_payload_for_review($item);
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
			self::render_context_memory_panel($payload, $item);
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
			$is_de        = ( $lang === 'de' );
			echo '<h2>' . esc_html(strtoupper($lang)) . '</h2>';
			// v2.1: DE-specific notice when worker is available
			if ( $is_de && EPV2_Worker_Client::is_available() ) {
				echo '<p style="color:#2e7d32;font-size:12px">✓ Python Worker доступен — кнопки «AI ↺» используют актуальный pipeline v21</p>';
			}
			echo '<table class="form-table"><tbody>';
			$media_id = 'epv2-media-' . $lang . '-' . $item_id;
			self::row('Заголовок', '<textarea id="epv2-title-de" name="payload[languages][' . esc_attr($lang) . '][title]" rows="3" class="large-text">' . esc_textarea((string) ($lang_payload['title'] ?? '')) . '</textarea>' . self::regen_button($item_id, $lang, 'title', $current_style) . ( $is_de ? self::regen_block_btn($item_id, 'title', 'AI ↺ Заголовок') : '' ));
			self::row('Лид', '<textarea id="epv2-lead-de" name="payload[languages][' . esc_attr($lang) . '][excerpt]" rows="4" class="large-text">' . esc_textarea((string) ($lang_payload['excerpt'] ?? '')) . '</textarea>' . self::regen_button($item_id, $lang, 'excerpt', $current_style) . ( $is_de ? self::regen_block_btn($item_id, 'lead', 'AI ↺ Лид') : '' ));
			self::row('Текст', '<textarea id="epv2-body-de" name="payload[languages][' . esc_attr($lang) . '][content]" rows="14" class="large-text code">' . esc_textarea((string) ($lang_payload['content'] ?? '')) . '</textarea>' . self::regen_button($item_id, $lang, 'content', $current_style) . ( $is_de ? self::regen_block_btn($item_id, 'body', 'AI ↺ Текст') : '' ));
			self::row('Медиа', '<input id="' . esc_attr($media_id) . '" name="payload[languages][' . esc_attr($lang) . '][media_url]" value="' . esc_attr((string) ($lang_payload['media_url'] ?? $payload['featured_media_url'] ?? $payload['media_url'] ?? '')) . '" class="regular-text"> ' . self::media_button($media_id) . self::regen_button($item_id, $lang, 'media_url', $current_style) . ( $is_de ? self::regen_block_btn($item_id, 'media', 'AI ↺ Медиа') : '' ));
			self::row('SEO', '<input id="epv2-seo-title-de" name="payload[languages][' . esc_attr($lang) . '][seo_title]" value="' . esc_attr((string) ($lang_payload['seo_title'] ?? '')) . '" class="regular-text" placeholder="SEO title"><br><textarea id="epv2-seo-meta-de" name="payload[languages][' . esc_attr($lang) . '][meta_description]" rows="2" class="large-text" placeholder="Meta description">' . esc_textarea((string) ($lang_payload['meta_description'] ?? '')) . '</textarea><br><input id="epv2-seo-slug-de" name="payload[languages][' . esc_attr($lang) . '][slug]" value="' . esc_attr((string) ($lang_payload['slug'] ?? '')) . '" class="regular-text" placeholder="slug"><br><textarea id="epv2-seo-kw-de" name="payload[languages][' . esc_attr($lang) . '][focus_keywords]" rows="2" class="large-text" placeholder="Один ключ на строку">' . esc_textarea(implode("\n", (array) ($lang_payload['focus_keywords'] ?? []))) . '</textarea>' . ( $is_de ? self::regen_block_btn($item_id, 'seo', 'AI ↺ SEO') : '' ));
			echo '</tbody></table>';
		}

		submit_button('Сохранить черновой пакет');
		echo ' <a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv2_review_ready_publish&id=' . $item_id), 'epv2_review_ready_publish_' . $item_id)) . '">Сохранить и подготовить к публикации</a>';
			if (in_array((string) $item->state, ['ready_review', 'ready_publish'], true)) {
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
		self::row('Gemini API ключ', self::secret_input('ai_keys[gemini]', (string) ($settings['ai_keys']['gemini'] ?? '')));
		self::row('Gemini: web grounding', '<label><input type="checkbox" name="gemini_search_grounding_enabled"' . checked(! empty($settings['gemini_search_grounding_enabled']), true, false) . '> использовать Google Search для черновиков и тестов</label>');
		self::row('Gemini: URL context', '<label><input type="checkbox" name="gemini_url_context_enabled"' . checked(! empty($settings['gemini_url_context_enabled']), true, false) . '> учитывать URL первоисточника как контекст</label>');
		self::row('Gemini: URL в промте', '<label><input type="checkbox" name="gemini_use_source_url_in_prompt"' . checked(! empty($settings['gemini_use_source_url_in_prompt']), true, false) . '> явно передавать URL источника в prompt</label>');
		self::row('Gemini: требовать ссылки', '<label><input type="checkbox" name="gemini_require_citations"' . checked(! empty($settings['gemini_require_citations']), true, false) . '> просить модель вернуть цитаты/ссылочные опоры в raw-ответе</label>');
		self::row('DeepSeek API ключ', self::secret_input('ai_keys[deepseek]', (string) ($settings['ai_keys']['deepseek'] ?? '')));
		self::row('OpenAI API ключ', self::secret_input('ai_keys[openai]', (string) ($settings['ai_keys']['openai'] ?? '')));
		self::row('Anthropic API ключ', self::secret_input('ai_keys[anthropic]', (string) ($settings['ai_keys']['anthropic'] ?? '')));
		self::row('Pexels API ключ', self::secret_input('image_keys[pexels]', (string) ($settings['image_keys']['pexels'] ?? '')));
		self::row('Unsplash API ключ', self::secret_input('image_keys[unsplash]', (string) ($settings['image_keys']['unsplash'] ?? '')));
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
		self::row('Worker: PYTHONPATH', '<input name="worker_src_dir" value="' . esc_attr((string) ($settings['worker_src_dir'] ?? '/root/projects/europulse/worker-v21/src')) . '" class="regular-text code">');
		self::row('Worker: timeout (сек)', '<input name="worker_timeout_seconds" type="number" value="' . (int) ($settings['worker_timeout_seconds'] ?? 180) . '" class="small-text">');
		self::row('Worker: shared secret', self::secret_input('worker_shared_secret', (string) ($settings['worker_shared_secret'] ?? ''), 'regular-text code'));
		self::row('Хранить завершённые элементы очереди (дней)', '<input name="queue_retention_days" type="number" value="' . (int) $settings['queue_retention_days'] . '" class="small-text">');
		self::row('TTL для новых элементов очереди (часы)', '<input name="queue_new_ttl_hours" type="number" value="' . (int) $settings['queue_new_ttl_hours'] . '" class="small-text">');
		self::row('Максимум новых элементов на рубрику', '<input name="queue_new_max_per_category" type="number" value="' . (int) $settings['queue_new_max_per_category'] . '" class="small-text">');
		self::row('Максимум новых элементов на источник', '<input name="queue_new_max_per_source" type="number" value="' . (int) $settings['queue_new_max_per_source'] . '" class="small-text">');
		self::row('Промт для авто-режима', '<textarea name="prompts[auto_rewrite]" rows="8" class="large-text code">' . esc_textarea((string) ($settings['prompts']['auto_rewrite'] ?? '')) . '</textarea><p class="description">Используется для автоматического сбора и автогенерации.</p>');
		self::row('Промт для аналитики', '<textarea name="prompts[analysis_rewrite]" rows="8" class="large-text code">' . esc_textarea((string) ($settings['prompts']['analysis_rewrite'] ?? '')) . '</textarea><p class="description">Используется для weekly analysis и крупных аналитических материалов по теме.</p>');
		self::row('Промт для ручного режима', '<textarea name="prompts[manual_rewrite]" rows="12" class="large-text code">' . esc_textarea((string) ($settings['prompts']['manual_rewrite'] ?? $settings['prompts']['news_default'] ?? '')) . '</textarea><p class="description">Используется в ручной сборке, перегенерации полей и редакторской доработке. Это и есть основной большой редакторский prompt.</p>');
		self::row('Промт для SEO-доводки', '<textarea name="prompts[seo_refine]" rows="8" class="large-text code">' . esc_textarea((string) ($settings['prompts']['seo_refine'] ?? '')) . '</textarea><p class="description">Используется при финальной SEO и release доводке материала.</p>');
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
		echo '<p>Можно дать URL первоисточника или вставить текст вручную. Поддерживается ввод в блоки DE, UK или EN: система собирает немецкий master и дальше отправляет материал в обычную автоматическую доводку, переводы, SEO и публикационный pipeline.</p>';
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
		self::row('DE master', '<p><strong>Заголовок</strong><br><textarea name="draft[languages][de][title]" rows="3" class="large-text">' . esc_textarea((string) ($draft['languages']['de']['title'] ?? $draft['title'])) . '</textarea><br><button class="button" name="manual_op" value="rewrite_de_title">AI: переписать DE-заголовок</button></p><p><strong>Лид / dek</strong><br><textarea name="draft[languages][de][excerpt]" rows="4" class="large-text">' . esc_textarea((string) ($draft['languages']['de']['excerpt'] ?? $draft['excerpt'])) . '</textarea><br><button class="button" name="manual_op" value="rewrite_de_excerpt">AI: переписать DE-лид</button></p><p><strong>Текст</strong><br><textarea name="draft[languages][de][content]" rows="12" class="large-text code">' . esc_textarea((string) ($draft['languages']['de']['content'] ?? $draft['content'])) . '</textarea><br><button class="button" name="manual_op" value="rewrite_de_content">AI: переписать DE-текст</button></p>');
		self::row('UK input', '<p><strong>Заголовок</strong><br><textarea name="draft[languages][uk][title]" rows="2" class="large-text">' . esc_textarea((string) ($draft['languages']['uk']['title'] ?? '')) . '</textarea><br><button class="button" name="manual_op" value="rewrite_uk_title">AI: переписать UK-заголовок</button></p><p><strong>Лид / dek</strong><br><textarea name="draft[languages][uk][excerpt]" rows="3" class="large-text">' . esc_textarea((string) ($draft['languages']['uk']['excerpt'] ?? '')) . '</textarea><br><button class="button" name="manual_op" value="rewrite_uk_excerpt">AI: переписать UK-лід</button></p><p><strong>Текст</strong><br><textarea name="draft[languages][uk][content]" rows="10" class="large-text code">' . esc_textarea((string) ($draft['languages']['uk']['content'] ?? '')) . '</textarea><br><button class="button" name="manual_op" value="rewrite_uk_content">AI: переписать UK-текст</button></p>');
		self::row('EN input', '<p><strong>Заголовок</strong><br><textarea name="draft[languages][en][title]" rows="2" class="large-text">' . esc_textarea((string) ($draft['languages']['en']['title'] ?? '')) . '</textarea><br><button class="button" name="manual_op" value="rewrite_en_title">AI: переписать EN-title</button></p><p><strong>Лид / dek</strong><br><textarea name="draft[languages][en][excerpt]" rows="3" class="large-text">' . esc_textarea((string) ($draft['languages']['en']['excerpt'] ?? '')) . '</textarea><br><button class="button" name="manual_op" value="rewrite_en_excerpt">AI: переписать EN-lead</button></p><p><strong>Текст</strong><br><textarea name="draft[languages][en][content]" rows="10" class="large-text code">' . esc_textarea((string) ($draft['languages']['en']['content'] ?? '')) . '</textarea><br><button class="button" name="manual_op" value="rewrite_en_content">AI: переписать EN-text</button></p>');
		self::row('Сборка master', '<p><button class="button" name="manual_op" value="generate_master">Собрать или обновить DE master из заполненного DE, UK или EN блока</button></p><p class="description">Можно вставить текст в любой языковой блок. Система возьмёт лучший заполненный источник и соберёт немецкий master.</p>');
		self::row('Превью / featured media', '<input id="epv2-manual-featured-media" name="draft[featured_media_url]" value="' . esc_attr((string) $draft['featured_media_url']) . '" class="regular-text"> ' . self::media_button('epv2-manual-featured-media') . ' <span class="description">Можно вручную прикрепить главное фото.</span>');
		self::row('Медиа внутри статьи', '<textarea id="epv2-manual-inline-media" name="draft[inline_media_urls]" rows="5" class="large-text code" placeholder="Один URL на строку">' . esc_textarea(implode("\n", EPV2_Media::normalize_media_list($draft['inline_media_urls']))) . '</textarea><p>' . self::media_button('epv2-manual-inline-media', true) . ' <span class="description">Можно добавлять несколько изображений и видео. Featured media пойдёт в превью статьи, остальные — внутрь текста.</span></p>');
		echo '</tbody></table>';
		echo '<h2>SEO</h2><table class="form-table"><tbody>';
		self::row('SEO title', '<input name="draft[seo][seo_title]" value="' . esc_attr((string) ($draft['seo']['seo_title'] ?? '')) . '" class="regular-text">');
		self::row('Meta description', '<textarea name="draft[seo][meta_description]" rows="3" class="large-text">' . esc_textarea((string) ($draft['seo']['meta_description'] ?? '')) . '</textarea>');
		self::row('Slug', '<input name="draft[seo][slug]" value="' . esc_attr((string) ($draft['seo']['slug'] ?? '')) . '" class="regular-text">');
		self::row('SEO-ключи', '<textarea name="draft[seo][focus_keywords]" rows="3" class="large-text" placeholder="Один ключ на строку">' . esc_textarea(implode("\n", (array) ($draft['seo']['focus_keywords'] ?? []))) . '</textarea><p><button class="button" name="manual_op" value="seo_optimize">Сгенерировать и SEO-оптимизировать AI по заполненному блоку</button></p>');
		echo '</tbody></table>';
		echo '<p class="submit">';
		echo '<button class="button button-secondary" name="manual_op" value="save">Сохранить ручной черновик</button> ';
		echo '<button class="button button-secondary" name="manual_op" value="reset" onclick="return confirm(\'Очистить ручной черновик?\')">Очистить</button> ';
		echo '<button class="button button-primary" name="manual_op" value="to_review">Сгенерировать и отправить в автоматику</button>';
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
		self::require_manage_capability();
		EPV2_Settings::set_all($_POST);
		EPV2_Jobs::clear_scheduled();
		EPV2_Jobs::schedule_recurring();
		wp_safe_redirect(admin_url('admin.php?page=epv2-settings&updated=1'));
		exit;
	}

	public static function run_collect(): void {
		check_admin_referer('epv2_run_collect');
		self::require_manage_capability();
		EPV2_Jobs::enqueue_collect();
		if (! EPV2_Jobs::automation_paused()) {
			EPV2_Jobs::enqueue_process();
			if ((string) EPV2_Settings::get('mode', 'semi') === 'auto') {
				EPV2_Jobs::enqueue_publish();
			}
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&queued=collect'));
		exit;
	}

	public static function run_process(): void {
		check_admin_referer('epv2_run_process');
		self::require_manage_capability();
		EPV2_Jobs::enqueue_process();
		if (! EPV2_Jobs::automation_paused() && (string) EPV2_Settings::get('mode', 'semi') === 'auto') {
			EPV2_Jobs::enqueue_publish();
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&queued=process'));
		exit;
	}

	public static function run_publish(): void {
		check_admin_referer('epv2_run_publish');
		self::require_manage_capability();
		EPV2_Jobs::enqueue_publish();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&queued=publish'));
		exit;
	}

	public static function pause_automation(): void {
		check_admin_referer('epv2_pause_automation');
		self::require_manage_capability();
		EPV2_Jobs::pause_automation();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&automation=paused'));
		exit;
	}

	public static function resume_automation(): void {
		check_admin_referer('epv2_resume_automation');
		self::require_manage_capability();
		EPV2_Jobs::resume_automation();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&automation=running'));
		exit;
	}

	public static function resume_automation_without_collect(): void {
		check_admin_referer('epv2_resume_automation_without_collect');
		self::require_manage_capability();
		EPV2_Jobs::pause_collect();
		EPV2_Jobs::resume_automation();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&automation=running&collect=paused'));
		exit;
	}

	public static function pause_collect(): void {
		check_admin_referer('epv2_pause_collect');
		self::require_manage_capability();
		EPV2_Jobs::pause_collect();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&collect=paused'));
		exit;
	}

	public static function resume_collect(): void {
		check_admin_referer('epv2_resume_collect');
		self::require_manage_capability();
		EPV2_Jobs::resume_collect();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&collect=running'));
		exit;
	}

	public static function reset_stats(): void {
		check_admin_referer('epv2_reset_stats');
		self::require_manage_capability();
		EPV2_Stats::reset_today();
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard&stats_reset=1'));
		exit;
	}

	public static function prune_queue(): void {
		check_admin_referer('epv2_prune_queue');
		self::require_manage_capability();
		EPV2_Queue::prune_stale((int) EPV2_Settings::get('queue_retention_days', 3));
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue&pruned=1'));
		exit;
	}

	public static function queue_to_publish(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_queue_to_publish_' . $id);
		self::require_manage_capability();
		if ($id > 0) {
			$item = EPV2_Queue::get_item($id);
			if ($item) {
				$payload = EPV2_Review::decode_payload((string) ($item->ai_payload ?? ''));
				if ($payload === []) {
					$payload = EPV2_Review::ensure_payload($item);
				}
				$payload = EPV2_Review::refresh_review_metrics($payload);
				if (EPV2_AI_Processor::transition_item_to_ready_publish($id, $payload)) {
					EPV2_Review::save_payload($id, $payload);
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
		self::require_manage_capability();
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
				if ((string) ($item->state ?? '') === 'published') {
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
		self::require_manage_capability();
		$referer = wp_get_referer() ?: admin_url('admin.php?page=epv2-queue');
		$is_review_referer = strpos((string) $referer, 'page=epv2-review') !== false;
		$item = EPV2_Queue::get_item($id);
		if ($item) {
			$payload = EPV2_Review::decode_payload((string) ($item->ai_payload ?? ''));
			if ($payload === []) {
				$payload = EPV2_Review::ensure_payload($item);
			}
			$payload = EPV2_Review::refresh_review_metrics($payload);
			if (! EPV2_AI_Processor::payload_is_terminal_publish_ready($payload)) {
				if ($is_review_referer) {
					self::set_review_notice('Публикация остановлена: материал не прошёл полный terminal publish-grade.', false);
					wp_safe_redirect($referer);
				} else {
					wp_safe_redirect(admin_url('admin.php?page=epv2-queue&queue_notice=publish_blocked'));
				}
				exit;
			}
			EPV2_Review::save_payload($id, $payload);
			try {
				EPV2_Publisher::publish_item($item, 'publish');
				if ($is_review_referer) {
					self::set_review_notice('Материал опубликован.', true);
					wp_safe_redirect($referer);
				} else {
					wp_safe_redirect(admin_url('admin.php?page=epv2-queue&queue_notice=published_now'));
				}
				exit;
			} catch (Throwable $e) {
				if ($is_review_referer) {
					self::set_review_notice('Публикация не выполнена: ' . $e->getMessage(), false);
					wp_safe_redirect($referer);
				} else {
					set_transient('epv2_queue_notice_' . get_current_user_id(), [
						'success' => false,
						'message' => 'Публикация не выполнена: ' . $e->getMessage(),
					], MINUTE_IN_SECONDS * 5);
					wp_safe_redirect(admin_url('admin.php?page=epv2-queue&queue_notice=publish_failed'));
				}
				exit;
			}
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	public static function review_ready_publish(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_review_ready_publish_' . $id);
		self::require_manage_capability();
		if ($id > 0) {
			$item = EPV2_Queue::get_item($id);
			if ($item) {
				$payload = EPV2_Review::decode_payload((string) ($item->ai_payload ?? ''));
				if ($payload === []) {
					$payload = EPV2_Review::ensure_payload($item);
				}
				$payload = EPV2_Review::refresh_review_metrics($payload);
				if (EPV2_AI_Processor::transition_item_to_ready_publish($id, $payload)) {
					EPV2_Review::save_payload($id, $payload);
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
		self::require_manage_capability();
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
		self::require_manage_capability();
		$draft_input = is_array($_POST['draft'] ?? null) ? $_POST['draft'] : [];
		$draft_input['editorial'] = is_array($_POST['editorial'] ?? null) ? $_POST['editorial'] : [];
		$draft = EPV2_Manual_Mode::normalize_draft($draft_input);
		$op = sanitize_text_field($_POST['manual_op'] ?? 'save');

		try {
			if (preg_match('/^rewrite_(de|uk|en)_(title|excerpt|content)$/', $op, $matches) === 1) {
				EPV2_Manual_Mode::rewrite_language_field($draft, (string) $matches[1], (string) $matches[2], (string) $draft['style']);
				self::set_manual_notice('Поле ' . strtoupper((string) $matches[1]) . ' / ' . (string) $matches[2] . ' перегенерировано.');
				wp_safe_redirect(admin_url('admin.php?page=epv2-manual'));
				exit;
			}
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
				case 'generate_master':
					EPV2_Manual_Mode::generate_de_master_draft($draft);
					self::set_manual_notice('Немецкий master собран из заполненного языкового блока.');
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
					EPV2_Manual_Mode::reset_draft();
					if (! EPV2_Jobs::automation_paused()) {
						EPV2_Jobs::enqueue_process();
						if ((string) EPV2_Settings::get('mode', 'semi') === 'auto') {
							EPV2_Jobs::enqueue_publish();
						}
					}
					self::set_manual_notice('Материал собран и отправлен в стандартную автоматическую доводку.');
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
		self::require_source_capability();
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
		self::require_manage_capability();
		$item = $id > 0 ? EPV2_Queue::get_item($id) : null;
		if (! $item) {
			wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
			exit;
		}

		$payload = EPV2_Review::decode_payload((string) ($item->ai_payload ?? ''));
		if ($payload === []) {
			$payload = EPV2_Review::ensure_payload($item);
		}
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
		$payload = EPV2_Review::refresh_review_metrics($payload);
		EPV2_Review::save_payload($id, $payload);
		EPV2_Queue::update_fields($id, ['category_final' => implode(',', $categories)]);
			if ((string) ($item->state ?? '') === 'published') {
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
		self::require_manage_capability();
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
		self::require_source_capability();
		EPV2_Sources::save($_POST);
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources&updated=1'));
		exit;
	}

	public static function toggle_source(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_toggle_source_' . $id);
		self::require_source_capability();
		if ($id > 0) {
			EPV2_Sources::toggle($id);
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources'));
		exit;
	}

	public static function delete_source(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_delete_source_' . $id);
		self::require_source_capability();
		if ($id > 0) {
			EPV2_Sources::delete($id);
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources'));
		exit;
	}

	public static function import_recommended_sources(): void {
		check_admin_referer('epv2_import_recommended_sources');
		self::require_source_capability();
		EPV2_Source_Library::import();
		wp_safe_redirect(admin_url('admin.php?page=epv2-sources&imported=1'));
		exit;
	}

	public static function delete_queue_items(): void {
		check_admin_referer('epv2_delete_queue_items');
		self::require_manage_capability();
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
		self::require_manage_capability();
		EPV2_Queue::clear_all();
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	/**
	 * Phase 3 — manual_review action: bulk-promote items to ready_publish.
	 * Operator decided the content is OK after triage.
	 */
	public static function promote_manual_review(): void {
		check_admin_referer('epv2_promote_manual_review');
		self::require_manage_capability();
		$ids_csv = sanitize_text_field((string) ($_POST['ids_csv'] ?? ''));
		$ids = array_filter(array_map('intval', explode(',', $ids_csv)));
		$promoted = 0;
		foreach ($ids as $id) {
			$row = EPV2_Queue::get_item($id);
			if (! $row || (string) ($row->state ?? '') !== 'manual_review') {
				continue;
			}
			EPV2_Queue::mark_state($id, 'ready_publish', [
				'error_message' => 'Promoted from manual_review by operator on ' . current_time('mysql'),
			]);
			$promoted++;
		}
		set_transient('epv2_admin_notice', sprintf('Промоушен в готово к публикации: %d из %d', $promoted, count($ids)), 30);
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue&state_filter=manual_review'));
		exit;
	}

	/**
	 * Phase 3 — reset publication schedule to the architecture-aligned
	 * default (7 windows from EPV2_Time_Planner::defaults). Drops any
	 * customised profile from the operator and lets defaults() take over.
	 */
	public static function reset_schedule_to_defaults(): void {
		check_admin_referer('epv2_reset_schedule_to_defaults');
		self::require_manage_capability();
		$opt = (array) get_option('epv2_settings', []);
		unset($opt['time_schedule_profile']);
		update_option('epv2_settings', $opt, false);
		set_transient('epv2_admin_notice', 'Расписание сброшено к архитектурному дефолту (7 окон).', 30);
		wp_safe_redirect(admin_url('admin.php?page=epv2-dashboard'));
		exit;
	}

	/**
	 * Phase 3 — manual_review action: re-process selected items.
	 * Resets state to `new` and clears the content_kind cache so the
	 * full_bundle pipeline runs fresh on the next orchestrator tick.
	 * Useful when the operator wants the AI to take another swing at
	 * an item that the per-step retry budget gave up on.
	 */
	public static function reprocess_manual_review(): void {
		check_admin_referer('epv2_reprocess_manual_review');
		self::require_manage_capability();
		$ids_csv = sanitize_text_field((string) ($_POST['ids_csv'] ?? ''));
		$ids = array_filter(array_map('intval', explode(',', $ids_csv)));
		$reprocessed = 0;
		global $wpdb;
		$runs_table = $wpdb->prefix . 'epv2_runs';
		foreach ($ids as $id) {
			$row = EPV2_Queue::get_item($id);
			if (! $row || (string) ($row->state ?? '') !== 'manual_review') {
				continue;
			}

			// 1. Drop cached story_card / content_kind so the next run
			// rebuilds them against current prompts + editorial calibration.
			$payload = json_decode((string) ($row->ai_payload ?? ''), true) ?: [];
			if (is_array($payload)) {
				unset($payload['_meta']['content_kind']);
				unset($payload['_meta']['story_card']);
				EPV2_Queue::update_fields($id, [
					'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				]);
			}

			// 2. Reset workflow counters in admin_notes._system. Without
			// this the maintenance loop reads the legacy attempt count
			// (workflow_step_attempts: 6) and re-quarantines on the
			// next 5-min tick before the worker has a chance to produce
			// fresh output. Strip every counter / status / blocker key.
			$notes = json_decode((string) ($row->admin_notes ?? ''), true) ?: [];
			$sys = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			foreach ([
				'workflow_step', 'workflow_step_attempts', 'workflow_step_status',
				'workflow_terminal_reason', 'quarantine_reason', 'last_stage_blocker',
				'retries', 'importance_score', 'importance_threshold', 'next_operator_action',
			] as $key) {
				unset($sys[$key]);
			}
			$notes['_system'] = $sys;
			EPV2_Queue::update_fields($id, [
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);

			// 3. Clear the item's process-run history.
			// recent_process_attempt_count() counts records in
			// ep_epv2_runs over a 2-hour window; legacy entries from
			// the failed cycle would otherwise be added to fresh attempts
			// and trip the per-step limit again.
			$wpdb->query($wpdb->prepare(
				"DELETE FROM `{$runs_table}`
				 WHERE job_name = 'process'
				   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.last_item_id')) AS UNSIGNED) = %d",
				$id
			));

			// 4. Flip back to `new` so the orchestrator picks the item
			// up on the next process tick.
			EPV2_Queue::mark_state($id, 'new', [
				'error_message' => 'Reprocess from manual_review by operator on ' . current_time('mysql'),
			]);
			$reprocessed++;
		}
		set_transient('epv2_admin_notice', sprintf('Отправлено на повторную обработку: %d из %d (полный сброс счётчиков и run-history)', $reprocessed, count($ids)), 30);
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue&state_filter=manual_review'));
		exit;
	}

	/**
	 * Phase 3 — manual_review action: bulk-reject items.
	 * Operator decided the content is not worth publishing after triage.
	 */
	public static function reject_manual_review(): void {
		check_admin_referer('epv2_reject_manual_review');
		self::require_manage_capability();
		$ids_csv = sanitize_text_field((string) ($_POST['ids_csv'] ?? ''));
		$ids = array_filter(array_map('intval', explode(',', $ids_csv)));
		$rejected = 0;
		foreach ($ids as $id) {
			$row = EPV2_Queue::get_item($id);
			if (! $row || (string) ($row->state ?? '') !== 'manual_review') {
				continue;
			}
			EPV2_Queue::mark_state($id, 'rejected', [
				'error_message' => 'Rejected from manual_review by operator on ' . current_time('mysql'),
			]);
			$rejected++;
		}
		set_transient('epv2_admin_notice', sprintf('Отклонено: %d из %d', $rejected, count($ids)), 30);
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue&state_filter=manual_review'));
		exit;
	}

	public static function clear_rejected_queue(): void {
		check_admin_referer('epv2_clear_rejected_queue');
		self::require_manage_capability();
		$items = EPV2_Queue::get_queue_items_summary([
			'limit' => 1000,
			'state' => 'rejected',
		]);
		if ($items !== []) {
			EPV2_Queue::delete_items(array_map(static fn($item) => (int) $item->id, $items));
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	public static function delete_queue_item(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_delete_queue_item_' . $id);
		self::require_manage_capability();
		if ($id > 0) {
			EPV2_Queue::delete_items([$id]);
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	public static function delete_published_post(): void {
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv2_delete_published_post_' . $id);
		self::require_manage_capability();
		if ($id > 0) {
			$item = EPV2_Queue::get_item($id);
			if ($item) {
				$post_ids = self::queue_post_ids($item);
				foreach ($post_ids as $post_id) {
					if ($post_id > 0) {
						wp_trash_post($post_id);
					}
				}
			}
			EPV2_Queue::delete_items([$id]);
		}
		wp_safe_redirect(admin_url('admin.php?page=epv2-queue'));
		exit;
	}

	private static function row(string $label, string $field): void {
		echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . '</td></tr>';
	}

	private static function secret_input(string $name, string $current_value, string $class = 'regular-text'): string {
		$has_value = trim($current_value) !== '';
		$placeholder = $has_value ? 'Сохранён — введите новый для замены' : 'Вставьте ключ';
		$hint = $has_value ? '<p class="description">Ключ сохранён и не выводится на страницу. Оставьте поле пустым, чтобы не менять его.</p>' : '';
		return '<input type="password" autocomplete="new-password" name="' . esc_attr($name) . '" value="" placeholder="' . esc_attr($placeholder) . '" class="' . esc_attr($class) . '">' . $hint;
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

	private static function queue_deferred_publish_label(object $item, array $system, bool $capitalized = true): string {
		$not_before = (int) ($system['publish_not_before'] ?? 0);
		if (! empty($system['publish_deferred_by_category_limit'])) {
			$category = trim((string) (! empty($item->category_final) ? $item->category_final : ($item->category_proposed ?? '')));
			$label = 'Отложено: лимит рубрики';
			if ($category !== '') {
				$label .= ' ' . $category;
			}
			if ($not_before > 0) {
				$label .= ' до ' . wp_date('H:i', $not_before);
			}
			return $capitalized ? $label : mb_strtolower($label);
		}
		if (! empty($system['publish_deferred_by_daily_limit'])) {
			$label = 'Отложено: дневной лимит';
			if ($not_before > 0) {
				$label .= ' до ' . wp_date('H:i', $not_before);
			}
			return $capitalized ? $label : mb_strtolower($label);
		}
		return '';
	}

	private static function queue_finish_attempt_label(array $system): string {
		$retries = is_array($system['retries'] ?? null) ? $system['retries'] : [];
		$attempt = (int) ($retries['review_finish'] ?? 0);
		if ($attempt <= 0) {
			return '';
		}
		return ' · попытка ' . $attempt . '/2';
	}

	private static function queue_state_label(object $item): string {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$live_status = trim((string) ($notes['_system']['live_status'] ?? ''));
		$retry_after = trim((string) ($notes['_system']['retry_after'] ?? ''));
		$state = (string) $item->state;
		$user_state = EPV2_Queue::user_facing_state_for_row($item);
		$classification = EPV2_Queue::workflow_classification_for_row($item);
		$active_item_id = self::active_queue_item_id();
		$deferred_label = self::queue_deferred_publish_label($item, is_array($notes['_system'] ?? null) ? $notes['_system'] : []);
		if (in_array($user_state, ['ready_publish', 'publishing'], true)) {
			return $deferred_label !== '' ? $deferred_label : 'Готов к публикации';
		}
		if (self::orchestrator_v2_ui_enabled() && self::is_recoverable_queue_item($item)) {
			if ((int) ($item->id ?? 0) === $active_item_id) {
				return 'В работе · ' . self::queue_progress_percent($item) . '% · ' . self::queue_progress_stage_label($item);
			}
			if ($retry_after !== '') {
				$retry_ts = strtotime($retry_after);
				if ($retry_ts && $retry_ts > time()) {
					return 'Пауза доводки до ' . wp_date('H:i', $retry_ts);
				}
			}
			return 'Новый';
		}
		if (self::is_recoverable_queue_item($item)) {
			if ((int) ($item->id ?? 0) === $active_item_id) {
				return 'В работе · ' . self::queue_progress_percent($item) . '% · ' . self::queue_progress_stage_label($item);
			}
			if ($retry_after !== '') {
				$retry_ts = strtotime($retry_after);
				if ($retry_ts && $retry_ts > time()) {
					return 'Пауза доводки до ' . wp_date('H:i', $retry_ts);
				}
			}
			return 'Новый';
		}
		if ($state === 'published') {
			$statuses = self::post_statuses_for_queue($item);
			if (empty($statuses)) {
				return 'Ошибка публикации';
			}
			if (count(array_unique($statuses)) > 1) {
				return 'Создано частично';
			}
			return match ($statuses[0]) {
				'publish' => 'Опубликован',
				'pending' => 'Готов к публикации',
				default => 'Готов к публикации',
			};
		}
		if ($live_status !== '' && in_array($state, ['processing_de', 'publishing'], true)) {
			return $live_status;
		}
		$labels = [
			'new' => 'Новый',
			'active' => 'В работе',
			'ready_publish' => 'Готов к публикации',
			'publishing' => 'Публикуется',
			'duplicate' => 'Дубликат',
			'error' => 'Ошибка',
			'rejected' => 'Отклонён',
		];
		$label = $labels[$user_state] ?? $user_state;
		if (($classification['class'] ?? '') === 'manual') {
			$label = 'Требует проверки';
		} elseif (($classification['class'] ?? '') === 'terminal' && $user_state === 'error') {
			$label = 'Техническая ошибка';
		}
		if ($user_state === 'ready_publish' && $retry_after !== '') {
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
		$timestamp = self::queue_published_timestamp($item);
		if ($timestamp <= 0) {
			return '—';
		}
		return wp_date('Y-m-d H:i:s', $timestamp);
	}

	private static function queue_published_timestamp(object $item): int {
		$primary_id = self::queue_primary_post_id($item);
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
		$primary_id = self::queue_primary_post_id($item);
		if ($primary_id <= 0) {
			return '<span style="color:#8c8f94">—</span>';
		}
		$url = get_permalink($primary_id);
		if (! $url) {
			return '<span style="color:#8c8f94">—</span>';
		}
		return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Открыть пост</a>';
	}

	private static function queue_site_datetime(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '—';
		}
		$timestamp = strtotime($value . ' UTC');
		if ($timestamp === false || $timestamp <= 0) {
			$timestamp = strtotime($value);
		}
		if ($timestamp === false || $timestamp <= 0) {
			return $value;
		}
		return wp_date('Y-m-d H:i:s', $timestamp);
	}

	private static function queue_post_edit_url(object $item): string {
		$primary_id = self::queue_primary_post_id($item);
		if ($primary_id <= 0) {
			return '';
		}
		return (string) admin_url('post.php?post=' . $primary_id . '&action=edit');
	}

	private static function queue_primary_post_id(object $item): int {
		$post_ids = self::queue_post_ids($item);
		return (int) ($post_ids['de'] ?? $item->post_id ?? 0);
	}

	private static function queue_post_ids(object $item): array {
		$payload = json_decode((string) ($item->publish_payload ?? ''), true);
		$post_ids = is_array($payload['post_ids'] ?? null) ? $payload['post_ids'] : [];
		$normalized = [];
		foreach ($post_ids as $lang => $post_id) {
			$post_id = (int) $post_id;
			if ($post_id > 0) {
				$normalized[(string) $lang] = $post_id;
			}
		}
		$fallback_id = (int) ($item->post_id ?? 0);
		if ($fallback_id > 0 && ! isset($normalized['de'])) {
			$normalized['de'] = $fallback_id;
		}
		return $normalized;
	}

	/**
	 * v2.1: AJAX per-block regeneration button (uses Python worker).
	 */
	private static function regen_block_btn( int $item_id, string $block, string $label ): string {
		$nonce = wp_create_nonce( 'epv2_regen_block_' . $item_id );
		return ' <button type="button" class="button button-small epv2-regen-block-btn"'
			. ' data-item-id="' . esc_attr( (string) $item_id ) . '"'
			. ' data-block="' . esc_attr( $block ) . '"'
			. ' data-nonce="' . esc_attr( $nonce ) . '"'
			. ' style="background:#1d4ed8;color:#fff;border-color:#1d4ed8">'
			. esc_html( $label )
			. '</button>';
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

	private static function set_review_notice(string $message, bool $success = true): void {
		set_transient('epv2_review_notice_' . get_current_user_id(), [
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

	private static function queue_release_badge(object $item): string {
		$payload = self::queue_cached_payload($item);
		$release = is_array($payload['_meta']['release_quality'] ?? null) ? $payload['_meta']['release_quality'] : [];
		$google = is_array($payload['_meta']['google_quality'] ?? null) ? $payload['_meta']['google_quality'] : [];
		if ($release === [] && $google === []) {
			return '<span style="color:#8c8f94">—</span>';
		}
		$release_score = (int) ($release['score'] ?? 0);
		$google_score = (int) ($google['score'] ?? 0);
		$release_pass = ! empty($release['pass']);
		$google_pass = ! empty($google['pass']);
		$bg = ($release_pass && $google_pass) ? '#dcfce7' : (($release_score >= 80 && $google_score >= 80) ? '#fef3c7' : '#fee2e2');
		$color = ($release_pass && $google_pass) ? '#166534' : (($release_score >= 80 && $google_score >= 80) ? '#92400e' : '#991b1b');
		$title_parts = [];
		if ($release !== []) {
			$title_parts[] = 'Release: ' . $release_score;
		}
		if ($google !== []) {
			$title_parts[] = 'Google: ' . $google_score;
		}
		return '<span title="' . esc_attr(implode(' | ', $title_parts)) . '" style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:11px;font-weight:700">' . esc_html($release_score . ' / ' . $google_score) . '</span>';
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

	private static function queue_light_issue_summary(array $blockers): string {
		if ($blockers === []) {
			return '<span style="color:#166534">OK</span>';
		}
		$visible = array_slice(array_values(array_filter(array_map('trim', $blockers))), 0, 2);
		if ($visible === []) {
			return '<span style="color:#166534">OK</span>';
		}
		$label = implode(' | ', $visible);
		if (count($blockers) > count($visible)) {
			$label .= ' | +' . (count($blockers) - count($visible));
		}
		return '<span title="' . esc_attr(implode(' | ', $blockers)) . '" style="color:#991b1b">' . esc_html($label) . '</span>';
	}

	private static function queue_cached_payload(object $item): array {
		if (isset($item->_epv2_admin_payload_cache) && is_array($item->_epv2_admin_payload_cache)) {
			return $item->_epv2_admin_payload_cache;
		}
		$payload = EPV2_Review::decode_payload((string) ($item->ai_payload ?? ''));
		$item->_epv2_admin_payload_cache = is_array($payload) ? $payload : [];
		return $item->_epv2_admin_payload_cache;
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
			'active' => 'В работе',
			'ready_publish' => 'Готов к публикации',
			'ready_review' => 'Готов к проверке',
			'publishing' => 'Публикуется',
			'manual_review' => 'Требует ручной проверки',
			'published' => 'Опубликовано',
			'duplicate' => 'Дубликат',
			'rejected' => 'Отклонено',
			'error' => 'Ошибка (legacy)',
		];
	}

	private static function queue_action_hint(string $state, ?object $item = null): string {
		$user_state = $item ? EPV2_Queue::user_facing_state_for_row($item) : $state;
		$classification = $item ? EPV2_Queue::workflow_classification_for_row($item) : [
			'class' => '',
			'reason' => '',
			'manual_kind' => '',
			'user_state' => $user_state,
		];
		if (self::orchestrator_v2_ui_enabled() && $item && self::is_recoverable_queue_item($item)) {
			if ((int) ($item->id ?? 0) === self::active_queue_item_id()) {
				return 'Сейчас это единственный активный материал. Система должна довести его до готовности к публикации, прежде чем взять следующий.';
			}
			return 'Материал ждёт своей очереди и останется в списке новых, пока не освободится единственный рабочий слот.';
		}
		if ($item && in_array($user_state, ['ready_publish', 'publishing'], true)) {
			return 'Материал полностью готов и ждёт ближайшего автоматического цикла публикации.';
		}
		if ($item && self::is_recoverable_queue_item($item)) {
			if ((int) ($item->id ?? 0) === self::active_queue_item_id()) {
				return 'Сейчас это единственный активный материал. Система должна довести его до готовности к публикации, прежде чем взять следующий.';
			}
			return 'Материал ждёт своей очереди и останется в списке новых, пока не освободится единственный рабочий слот.';
		}
		if (($classification['class'] ?? '') === 'manual') {
			return match ((string) ($classification['manual_kind'] ?? '')) {
				'translation' => 'Материал требует ручной проверки перевода. Автоматическая языковая доводка остановлена до решения редактора.',
				'media' => 'Материал требует ручной проверки медиа. Автоматическая медиадоводка остановлена до решения редактора.',
				default => 'Материал требует ручной редакционной проверки и не будет автоматически продолжен без явного решения.',
			};
		}
		if (($classification['class'] ?? '') === 'terminal' && $user_state === 'error') {
			return 'Это технический terminal-state. Материал остановлен из-за ошибки обработки, а не отклонён редакционно.';
		}
		return match ($state) {
			'new' => 'Материал ждёт своей очереди на автоматическую обработку.',
			'active' => 'Материал сейчас находится в работе.',
			'ready_publish' => 'Материал уже готов к публикации.',
			'publishing' => 'Материал сейчас публикуется на сайте.',
			'published' => 'Материал уже опубликован. "Редактировать пост" открывает его в редакторе WordPress, а "Удалить новость" отправляет опубликованные посты в корзину и убирает запись из очереди.',
			'duplicate' => 'Это дубль; публикация отключена.',
			'rejected' => 'Материал отсеян фильтрами и не пойдёт в публикацию.',
			'error' => 'Есть ошибка обработки; сначала открой и проверь материал.',
			default => 'Сначала открой материал и проверь пакет.',
		};
	}

	private static function queue_progress_percent(object $item): int {
		$payload = self::queue_cached_payload($item);
		$checklist = is_array($payload['_meta']['stage_checklist'] ?? null) ? $payload['_meta']['stage_checklist'] : [];
		$publish_ready = EPV2_AI_Processor::payload_is_publish_ready($payload);
		$steps = [
			'source_received',
			'initial_analysis_done',
			'context_saved',
			'context_analysis_done',
			'dossier_built',
			'de_master_ready',
			'uk_ready',
			'en_ready',
			'translations_ready',
			'publish_finish_ready',
			'ready_publish',
		];
		$done = 0;
		foreach ($steps as $step) {
			if (! empty($checklist[$step])) {
				$done++;
			}
		}
		$state = (string) ($item->state ?? '');
		if (in_array($state, ['publishing', 'published'], true)) {
			return 100;
		}
		if ($publish_ready || $state === 'ready_publish') {
			return 95;
		}
		if ($done <= 0) {
			return $state === 'new' ? 0 : 5;
		}
		$percent = (int) floor(($done / count($steps)) * 100);
		return max(5, min(90, $percent));
	}

	private static function queue_progress_stage_label(object $item): string {
		$payload = self::queue_cached_payload($item);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$live_status = trim((string) ($system['live_status'] ?? ''));
		$workflow_status = (string) ($system['workflow_step_status'] ?? '');
		$state = (string) ($item->state ?? '');

		if ($state === 'publishing') {
			return 'публикует на сайт';
		}
		if ($state === 'ready_publish') {
			$deferred_label = self::queue_deferred_publish_label($item, $system, false);
			return $deferred_label !== '' ? $deferred_label : 'ждёт слот публикации';
		}
		if (! empty($checklist['ready_publish'])) {
			return 'готов к публикации';
		}
		if (! empty($checklist['publish_finish_ready'])) {
			if (empty($checklist['translations_ready'])) {
				return 'ждёт переводы';
			}
			return 'собирает пакет публикации';
		}
		if (! empty($checklist['de_master_ready'])) {
			if (empty($checklist['uk_ready']) || empty($checklist['en_ready'])) {
				return 'готовит переводы';
			}
			return 'проверяет готовность';
		}
		if (! empty($checklist['dossier_built'])) {
			return 'пишет DE master';
		}
		if (! empty($checklist['context_analysis_done'])) {
			return 'собирает фактуру';
		}
		if (! empty($checklist['initial_analysis_done'])) {
			return 'анализирует материал';
		}
		$attempt_label = self::queue_finish_attempt_label($system);
		if ($live_status !== '') {
			return mb_strtolower($live_status . $attempt_label);
		}
		return match ($workflow_status) {
			'claimed' => 'материал взят в работу' . $attempt_label,
			'released_as_noncanonical_owner' => 'переназначает рабочий слот',
			default => 'обрабатывает материал',
		};
	}

	private static function render_context_memory_panel(array $payload, object $item): void {
		$memory = is_array($payload['_meta']['context_memory'] ?? null) ? $payload['_meta']['context_memory'] : [];
		if ($memory === []) {
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$memory = is_array($notes['_system']['context_memory'] ?? null) ? $notes['_system']['context_memory'] : [];
		}
		if ($memory === []) {
			return;
		}
		$memory = self::context_memory_for_russian_display($memory);
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:16px 0">';
		echo '<h2 style="margin-top:0">Контекст материала</h2>';
		$rows = [
			'Краткая суть' => (string) ($memory['summary'] ?? ''),
			'Событие' => (string) ($memory['event_title'] ?? ''),
			'Место' => (string) ($memory['venue'] ?? ''),
			'Когда' => (string) ($memory['datetime_text'] ?? ''),
			'Сниппет' => (string) ($memory['body_snippet'] ?? ''),
		];
		echo '<table class="form-table"><tbody>';
		foreach ($rows as $label => $value) {
			if (trim($value) === '') {
				continue;
			}
			self::row($label, '<div style="white-space:pre-wrap">' . esc_html($value) . '</div>');
		}
		foreach ([
			'Участники' => (array) ($memory['participants'] ?? []),
			'Имена и сущности' => (array) ($memory['entities'] ?? []),
			'Локации' => (array) ($memory['locations'] ?? []),
			'Даты' => (array) ($memory['dates'] ?? []),
			'Суммы и цены' => (array) ($memory['money_values'] ?? []),
			'Ключевые тезисы' => (array) ($memory['theses'] ?? []),
			'Факты' => (array) ($memory['fact_snippets'] ?? []),
			'Поисковые опоры' => (array) ($memory['search_terms'] ?? []),
		] as $label => $values) {
			$values = array_values(array_filter(array_map('strval', $values)));
			if ($values === []) {
				continue;
			}
			self::row($label, '<div style="white-space:pre-wrap">' . esc_html(implode("\n", $values)) . '</div>');
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private static function context_memory_for_russian_display(array $memory): array {
		if ($memory === [] || ! self::context_memory_needs_russian_translation($memory)) {
			return $memory;
		}

		$cache_key = 'epv2_context_memory_ru_' . md5((string) wp_json_encode($memory, JSON_UNESCAPED_UNICODE));
		$cached = get_transient($cache_key);
		if (is_array($cached) && $cached !== []) {
			return $cached;
		}

		$translated = self::translate_context_memory_to_russian($memory);
		if ($translated !== [] && $translated !== $memory) {
			set_transient($cache_key, $translated, DAY_IN_SECONDS);
			return $translated;
		}

		return $memory;
	}

	private static function context_memory_needs_russian_translation(array $memory): bool {
		foreach ($memory as $value) {
			if (is_array($value)) {
				foreach ($value as $entry) {
					$text = trim((string) $entry);
					if ($text !== '' && preg_match('/\p{Cyrillic}/u', $text) !== 1) {
						return true;
					}
				}
				continue;
			}
			$text = trim((string) $value);
			if ($text !== '' && preg_match('/\p{Cyrillic}/u', $text) !== 1) {
				return true;
			}
		}

		return false;
	}

	private static function translate_context_memory_to_russian(array $memory): array {
		if (! class_exists('EPV2_Settings') || ! class_exists('EPV2_AI_Client')) {
			return [];
		}

		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key'])) {
			return [];
		}

		$config['max_tokens'] = 900;
		$config['timeout'] = 18;

		try {
			$result = EPV2_AI_Client::generate($config, [
				[
					'role' => 'system',
					'content' => 'Переведи JSON-контекст материала на русский язык. Верни только JSON с теми же ключами. Сохраняй имена собственные, числа, географические названия, аббревиатуры и фактический смысл. Ничего не сокращай и не добавляй.',
				],
				[
					'role' => 'user',
					'content' => wp_json_encode($memory, JSON_UNESCAPED_UNICODE),
				],
			]);
			$text = trim((string) ($result['text'] ?? ''));
			$text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?: $text;
			$text = preg_replace('/\s*```$/', '', $text) ?: $text;
			$data = json_decode($text, true);
			if (! is_array($data) || $data === []) {
				return [];
			}

			$translated = [];
			foreach ($memory as $key => $value) {
				if (is_array($value)) {
					$translated[$key] = array_values(array_filter(array_map('strval', (array) ($data[$key] ?? []))));
				} else {
					$translated[$key] = (string) ($data[$key] ?? $value);
				}
			}

			return $translated;
		} catch (Throwable $e) {
			return [];
		}
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
		if (isset($item->_epv2_admin_analysis_cache) && is_array($item->_epv2_admin_analysis_cache)) {
			return $item->_epv2_admin_analysis_cache;
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$item->_epv2_admin_analysis_cache = is_array($notes['selection'] ?? null) ? $notes['selection'] : [];
		return $item->_epv2_admin_analysis_cache;
	}

	private static function queue_page_fetch_limit(string $state_filter): int {
		$state_filter = sanitize_key($state_filter);
		if ($state_filter === 'published') {
			return 40;
		}
		if ($state_filter !== '') {
			return 100;
		}
		return self::QUEUE_PAGE_FETCH_LIMIT;
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
		$queue_snapshot_url = wp_json_encode(wp_nonce_url(admin_url('admin-ajax.php?action=epv2_queue_snapshot'), 'epv2_queue_snapshot'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$script = <<<'JS'
jQuery(function($){
  const epv2QueueSnapshotUrl = __EPV2_QUEUE_SNAPSHOT_URL__;
  const epv2ModelMap = __EPV2_MODEL_MAP__;
  const epv2UsageMap = __EPV2_USAGE_MAP__;
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
  let lastQueueRefreshAt = 0;
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
        refreshQueueBlocks(true);
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
	        refreshQueueBlocks(true);
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
    const applySnapshotSections = function(response) {
      if (!response || !response.success || !response.data || !response.data.sections) {
        return false;
      }
      let updated = false;
      Object.keys(response.data.sections).forEach(function(key) {
        const html = response.data.sections[key];
        const current = document.getElementById('epv2-queue-section-' + key);
        if (current && typeof html === 'string' && html !== '') {
          current.outerHTML = html;
          updated = true;
        }
      });
      return updated;
    };
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
    refreshQueueBlocks = function(force) {
      if (queueRefreshing) return;
      if (document.hidden) return;
      const now = Date.now();
      if (!force && lastQueueRefreshAt > 0 && now - lastQueueRefreshAt < __EPV2_QUEUE_SNAPSHOT_REFRESH_MS__) {
        return;
      }
      queueRefreshing = true;
      lastQueueRefreshAt = now;
      const params = new URLSearchParams(window.location.search);
      const url = new URL(epv2QueueSnapshotUrl, window.location.origin);
      ['orderby', 'order', 'state_filter', 'category_filter'].forEach(function(key){
        if (params.get(key)) {
          url.searchParams.set(key, params.get(key));
        }
      });
      $.get(url.toString())
        .done(function(response){
          if (response && response.success && response.data) {
            const sectionsApplied = applySnapshotSections(response);
            if (!sectionsApplied && response.data.html) {
              queueBlocks.innerHTML = response.data.html;
            }
            applySnapshotTargets(response);
            renderCollectCountdown();
            renderPublishCountdown();
          }
        })
        .always(function(){
          queueRefreshing = false;
        });
    };
    window.setTimeout(function() {
      if (typeof refreshQueueBlocks === 'function') {
        refreshQueueBlocks(true);
      }
    }, 1200);
    window.setInterval(refreshQueueBlocks, __EPV2_QUEUE_SNAPSHOT_REFRESH_MS__);
    document.addEventListener('visibilitychange', function() {
      if (!document.hidden && typeof refreshQueueBlocks === 'function') {
        refreshQueueBlocks(true);
      }
    });
  }

  // -----------------------------------------------------------------------
  // v2.1: Per-block AJAX regeneration via Python Worker
  // -----------------------------------------------------------------------
  jQuery(document).on('click', '.epv2-regen-block-btn', function() {
    var $btn    = jQuery(this);
    var itemId  = $btn.data('item-id');
    var block   = $btn.data('block');
    var nonce   = $btn.data('nonce');
    var origTxt = $btn.text();

    $btn.prop('disabled', true).text('⏳ Генерирую...');

    jQuery.post(ajaxurl, {
      action:  'epv2_regen_block',
      nonce:   nonce,
      item_id: itemId,
      block:   block,
    }, function(resp) {
      if (!resp.success) {
        alert('Ошибка: ' + (resp.data && resp.data.message ? resp.data.message : 'Неизвестная ошибка'));
        return;
      }
      var d = resp.data;
      if (block === 'title'  && d.title_de)   { jQuery('#epv2-title-de').val(d.title_de); }
      if (block === 'lead'   && d.lead_de)    { jQuery('#epv2-lead-de').val(d.lead_de); }
      if (block === 'body'   && d.body_de)    { jQuery('#epv2-body-de').val(d.body_de); }
      if (block === 'media'  && d.media_url)  {
        jQuery('[name*="[media_url]"]').first().val(d.media_url);
        jQuery('#epv2-media-de-' + itemId).val(d.media_url);
      }
      if (block === 'seo') {
        if (d.seo_title)        jQuery('#epv2-seo-title-de').val(d.seo_title);
        if (d.meta_description) jQuery('#epv2-seo-meta-de').val(d.meta_description);
        if (d.slug)             jQuery('#epv2-seo-slug-de').val(d.slug);
        if (d.keywords)         jQuery('#epv2-seo-kw-de').val(d.keywords.join('\n'));
      }
      $btn.text('✓ Готово').css('background','#166534');
      setTimeout(function(){ $btn.text(origTxt).css('background','#1d4ed8'); }, 3000);
    }).fail(function() {
      alert('Ошибка подключения к серверу.');
    }).always(function() {
      $btn.prop('disabled', false);
    });
  });
});
JS;
		return str_replace(
			['__EPV2_QUEUE_SNAPSHOT_URL__', '__EPV2_MODEL_MAP__', '__EPV2_USAGE_MAP__', '__EPV2_QUEUE_SNAPSHOT_REFRESH_MS__'],
			[$queue_snapshot_url, $model_map, $usage_map, (string) self::QUEUE_SNAPSHOT_REFRESH_MS],
			$script
		);
	}

	// =========================================================================
	// v2.1: Per-block regeneration via Python worker (wp_ajax_epv2_regen_block)
	// =========================================================================

	/**
	 * AJAX handler for per-block content regeneration.
	 *
	 * Expected POST fields:
	 *   nonce    — wp_create_nonce('epv2_regen_block_{id}')
	 *   item_id  — queue item ID
	 *   block    — "title" | "lead" | "body" | "media" | "seo"
	 *
	 * Returns JSON: { success: true, data: { title_de, lead_de, body_de,
	 *                 media_url, seo_title, meta_description, slug, keywords } }
	 */
	public static function regen_block(): void {
		$item_id = (int) ( $_POST['item_id'] ?? 0 );
		$block   = sanitize_key( $_POST['block'] ?? '' );

		if ( ! check_ajax_referer( 'epv2_regen_block_' . $item_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}
		if ( ! current_user_can( 'manage_europulse_autopilot' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}

		$allowed_blocks = [ 'title', 'lead', 'body', 'media', 'seo' ];
		if ( ! in_array( $block, $allowed_blocks, true ) ) {
			wp_send_json_error( [ 'message' => 'Unknown block: ' . $block ] );
		}

		$item = $item_id > 0 ? EPV2_Queue::get_item( $item_id ) : null;
		if ( ! $item ) {
			wp_send_json_error( [ 'message' => 'Item not found' ] );
		}

		if ( ! EPV2_Worker_Client::is_available() ) {
			wp_send_json_error( [ 'message' => 'Python worker is not running. Start it with: sudo systemctl start epv2-worker' ] );
		}

		try {
			// Build existing payload for partial regen context
			$existing_raw = EPV2_Review::get_payload( $item_id );
			$existing     = [];
			if ( is_array( $existing_raw ) ) {
				$de = $existing_raw['languages']['de'] ?? [];
				$existing = [
					'title_de'     => (string) ( $de['title']   ?? $item->original_title   ?? '' ),
					'lead_de'      => (string) ( $de['excerpt'] ?? $item->original_excerpt ?? '' ),
					'body_de'      => (string) ( $de['content'] ?? $item->original_content ?? '' ),
					'key_phrases'  => (array)  ( $existing_raw['tags'] ?? [] ),
					'content_type' => (string) ( $existing_raw['content_type'] ?? 'news' ),
				];
			}

			$result = EPV2_Worker_Client::process( $item, $block, $existing );
			$output = self::extract_regen_output( $result, $block );
			wp_send_json_success( $output );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Extract the relevant fields from a worker response for a given block.
	 */
	private static function extract_regen_output( array $result, string $block ): array {
		$de      = $result['german_master']  ?? [];
		$media   = $result['media_candidates'][0] ?? [];
		$output  = [];

		switch ( $block ) {
			case 'title':
				$output['title_de'] = (string) ( $de['title'] ?? '' );
				break;
			case 'lead':
				$output['lead_de'] = (string) ( $de['excerpt'] ?? '' );
				break;
			case 'body':
				$output['body_de'] = (string) ( $de['content'] ?? '' );
				break;
			case 'media':
				$output['media_url'] = (string) ( $result['featured_media_url'] ?? $media['url'] ?? '' );
				$output['attribution'] = (string) ( $media['source_url'] ?? '' );
				break;
			case 'seo':
				$output['seo_title']        = (string) ( $de['seo_title']        ?? '' );
				$output['meta_description'] = (string) ( $de['meta_description'] ?? '' );
				$output['slug']             = (string) ( $de['slug']             ?? '' );
				$output['keywords']         = (array)  ( $de['focus_keywords']   ?? [] );
				break;
		}
		return $output;
	}
}
