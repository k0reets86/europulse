<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Plugin {
	private const MENU_SLUG = 'epv3-dashboard';

	public static function init(): void {
		add_filter('cron_schedules', [self::class, 'cron_schedules']);
		EPV3_Orchestrator::register();
		EPV3_Orchestrator::schedule();

		add_action('admin_menu', [self::class, 'admin_menu']);
		add_action('admin_post_epv3_seed_test_item', [self::class, 'seed_test_item']);
		add_action('admin_post_epv3_run_tick', [self::class, 'run_tick']);
		add_action('admin_post_epv3_run_batch', [self::class, 'run_batch']);
		add_action('admin_post_epv3_save_settings', [self::class, 'save_settings']);
		add_action('admin_post_epv3_manual_submit', [self::class, 'manual_submit']);
		add_action('admin_post_epv3_requeue_item', [self::class, 'requeue_item']);
	}

	public static function cron_schedules(array $schedules): array {
		$schedules['epv3_5_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display' => 'Every 5 minutes (EPV3)',
		];

		return $schedules;
	}

	public static function admin_menu(): void {
		add_menu_page('EPV3', 'EPV3', 'manage_options', self::MENU_SLUG, [self::class, 'render_dashboard'], 'dashicons-admin-site', 59);
		add_submenu_page(self::MENU_SLUG, 'Обзор', 'Обзор', 'manage_options', self::MENU_SLUG, [self::class, 'render_dashboard']);
		add_submenu_page(self::MENU_SLUG, 'Источники', 'Источники', 'manage_options', 'epv3-sources', [self::class, 'render_sources']);
		add_submenu_page(self::MENU_SLUG, 'Очередь', 'Очередь', 'manage_options', 'epv3-queue', [self::class, 'render_queue']);
		add_submenu_page(self::MENU_SLUG, 'Настройки', 'Настройки', 'manage_options', 'epv3-settings', [self::class, 'render_settings']);
		add_submenu_page(self::MENU_SLUG, 'Ручной режим', 'Ручной режим', 'manage_options', 'epv3-manual', [self::class, 'render_manual']);
		add_submenu_page(self::MENU_SLUG, 'Проверка материала', 'Проверка материала', 'manage_options', 'epv3-review', [self::class, 'render_review']);
		add_submenu_page(self::MENU_SLUG, 'Логи', 'Логи', 'manage_options', 'epv3-logs', [self::class, 'render_logs']);
		add_submenu_page(self::MENU_SLUG, 'Запуски', 'Запуски', 'manage_options', 'epv3-runs', [self::class, 'render_runs']);
	}

	public static function render_dashboard(): void {
		$settings = EPV3_Settings::get_all();
		$counts = EPV3_Queue_Repository::counts_by_state();
		$items = EPV3_Queue_Repository::recent_items(8);
		$runs = EPV3_Runs_Repository::latest(8);
		$ready = (int) ($counts['ready_publish'] ?? 0);
		$queued = (int) ($counts['queued'] ?? 0);
		$retry = (int) ($counts['retry'] ?? 0);
		$published = (int) ($counts['published'] ?? 0);

		self::render_page_start('EPV3 Overview');
		self::render_notice();

		echo '<div class="epv3-actions">';
		self::render_action_button('Добавить тестовый item', 'epv3_seed_test_item', 'epv3_seed_test_item', true);
		self::render_action_button('Один tick', 'epv3_run_tick', 'epv3_run_tick');
		self::render_action_button('Пять tick подряд', 'epv3_run_batch', 'epv3_run_batch');
		echo '</div>';

		echo '<div class="epv3-grid">';
		self::metric_card('Режим', esc_html((string) $settings['mode']));
		self::metric_card('В очереди', (string) $queued);
		self::metric_card('На retry', (string) $retry);
		self::metric_card('Готово к публикации', (string) $ready);
		self::metric_card('Опубликовано', (string) $published);
		self::metric_card('DE-first', ! empty($settings['de_first']) ? 'Да' : 'Нет');
		self::metric_card('AI', esc_html((string) $settings['ai_provider']) . ' / ' . esc_html((string) $settings['ai_model']));
		self::metric_card('Пауза до публикации', (int) $settings['publish_delay_minutes'] . ' мин');
		echo '</div>';

		echo '<div class="epv3-card"><h2>Последние элементы очереди</h2>';
		self::render_queue_table($items, false);
		echo '</div>';

		echo '<div class="epv3-card"><h2>Последние запуски</h2>';
		self::render_runs_table($runs);
		echo '</div>';

		self::render_page_end();
	}

	public static function render_sources(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'epv2_sources';
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
		$sources = [];
		if ($exists) {
			$sources = $wpdb->get_results("SELECT id, name, type, language, category_bias, priority, is_active, last_error FROM {$table} ORDER BY is_active DESC, priority DESC, id DESC LIMIT 200");
		}

		self::render_page_start('EPV3 Sources');
		self::render_notice();
		echo '<div class="epv3-card"><p>В `EPV3` источники пока используются как reference-слой знаний и покрытия. Данные ниже читаются из таблицы `EPV2`, но выполнение идёт только через `EPV3`.</p></div>';
		echo '<div class="epv3-card"><h2>Источники</h2>';
		if (! $exists) {
			echo '<p>Таблица источников `EPV2` не найдена.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Название</th><th>Тип</th><th>Язык</th><th>Категория</th><th>Приоритет</th><th>Статус</th><th>Ошибка</th></tr></thead><tbody>';
			foreach ($sources as $source) {
				echo '<tr>';
				echo '<td>' . (int) $source->id . '</td>';
				echo '<td>' . esc_html((string) $source->name) . '</td>';
				echo '<td>' . esc_html((string) $source->type) . '</td>';
				echo '<td>' . esc_html((string) $source->language) . '</td>';
				echo '<td>' . esc_html((string) $source->category_bias) . '</td>';
				echo '<td>' . (int) $source->priority . '</td>';
				echo '<td>' . (! empty($source->is_active) ? 'active' : 'inactive') . '</td>';
				echo '<td>' . esc_html(wp_trim_words((string) $source->last_error, 12, '')) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		self::render_page_end();
	}

	public static function render_queue(): void {
		$state = sanitize_key((string) ($_GET['state_filter'] ?? ''));
		$stage = sanitize_key((string) ($_GET['stage_filter'] ?? ''));
		$search = sanitize_text_field((string) ($_GET['s'] ?? ''));
		$items = EPV3_Queue_Repository::get_items([
			'limit' => 200,
			'state' => $state !== '' ? $state : null,
			'stage' => $stage !== '' ? $stage : null,
			'search' => $search !== '' ? $search : null,
		]);

		self::render_page_start('EPV3 Queue');
		self::render_notice();
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="epv3-filter-bar">';
		echo '<input type="hidden" name="page" value="epv3-queue">';
		echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Поиск по title/URL">';
		echo self::select('state_filter', $state, self::state_options(), 'Все состояния');
		echo self::select('stage_filter', $stage, self::stage_options(), 'Все стадии');
		echo '<button class="button">Фильтровать</button>';
		echo '</form>';
		echo '<div class="epv3-actions">';
		self::render_action_button('Один tick', 'epv3_run_tick', 'epv3_run_tick');
		self::render_action_button('Пять tick подряд', 'epv3_run_batch', 'epv3_run_batch');
		echo '</div>';
		echo '<div class="epv3-card">';
		self::render_queue_table($items, true);
		echo '</div>';
		self::render_page_end();
	}

	public static function render_settings(): void {
		$settings = EPV3_Settings::get_all();

		self::render_page_start('EPV3 Settings');
		self::render_notice();
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('epv3_save_settings');
		echo '<input type="hidden" name="action" value="epv3_save_settings">';
		echo '<div class="epv3-card"><h2>Основное</h2><table class="form-table"><tbody>';
		self::form_row('Режим', self::select('mode', (string) $settings['mode'], ['disabled' => 'disabled', 'manual' => 'manual', 'auto' => 'auto']));
		self::form_row('Статус новых постов', self::select('default_post_status', (string) $settings['default_post_status'], ['draft' => 'draft', 'publish' => 'publish', 'pending' => 'pending']));
		self::form_row('Основной язык', '<input class="regular-text" name="primary_language" value="' . esc_attr((string) $settings['primary_language']) . '">');
		self::form_row('DE-first', '<label><input type="checkbox" name="de_first" value="1"' . checked(! empty($settings['de_first']), true, false) . '> включено</label>');
		echo '</tbody></table></div>';

		echo '<div class="epv3-card"><h2>Интервалы и очередь</h2><table class="form-table"><tbody>';
		self::form_row('Collect interval (мин)', '<input type="number" min="5" class="small-text" name="collect_interval_minutes" value="' . (int) $settings['collect_interval_minutes'] . '">');
		self::form_row('Process interval (мин)', '<input type="number" min="1" class="small-text" name="process_interval_minutes" value="' . (int) $settings['process_interval_minutes'] . '">');
		self::form_row('Publish interval (мин)', '<input type="number" min="1" class="small-text" name="publish_interval_minutes" value="' . (int) $settings['publish_interval_minutes'] . '">');
		self::form_row('Пауза до публикации (мин)', '<input type="number" min="1" class="small-text" name="publish_delay_minutes" value="' . (int) $settings['publish_delay_minutes'] . '">');
		self::form_row('Process items per tick', '<input type="number" min="1" max="10" class="small-text" name="max_parallel_items" value="' . (int) $settings['max_parallel_items'] . '">');
		self::form_row('Publish items per tick', '<input type="number" min="1" max="10" class="small-text" name="max_publish_per_tick" value="' . (int) ($settings['max_publish_per_tick'] ?? 3) . '">');
		self::form_row('Worker timeout (сек)', '<input type="number" min="30" class="small-text" name="worker_timeout_seconds" value="' . (int) $settings['worker_timeout_seconds'] . '">');
		echo '</tbody></table></div>';

		echo '<div class="epv3-card"><h2>AI и качество</h2><table class="form-table"><tbody>';
		self::form_row('AI provider', '<input class="regular-text" name="ai_provider" value="' . esc_attr((string) $settings['ai_provider']) . '">');
		self::form_row('AI model', '<input class="regular-text" name="ai_model" value="' . esc_attr((string) $settings['ai_model']) . '">');
		self::form_row('Fallback provider', '<input class="regular-text" name="ai_fallback_provider" value="' . esc_attr((string) $settings['ai_fallback_provider']) . '">');
		self::form_row('Fallback model', '<input class="regular-text" name="ai_fallback_model" value="' . esc_attr((string) $settings['ai_fallback_model']) . '">');
		self::form_row('AI budget mode', self::select('ai_budget_mode', (string) $settings['ai_budget_mode'], ['low' => 'low', 'normal' => 'normal', 'high' => 'high']));
		self::form_row('Strictness', self::select('ai_selection_strictness', (string) $settings['ai_selection_strictness'], ['low' => 'low', 'medium' => 'medium', 'high' => 'high']));
		self::form_row('Rewrite style', '<input class="regular-text" name="rewrite_style" value="' . esc_attr((string) $settings['rewrite_style']) . '">');
		self::form_row('Min sources', '<input type="number" min="1" max="10" class="small-text" name="enrichment_min_sources" value="' . (int) $settings['enrichment_min_sources'] . '">');
		self::form_row('Target sources', '<input type="number" min="1" max="10" class="small-text" name="enrichment_target_sources" value="' . (int) $settings['enrichment_target_sources'] . '">');
		self::form_row('Gemini grounding', '<label><input type="checkbox" name="gemini_search_grounding_enabled" value="1"' . checked(! empty($settings['gemini_search_grounding_enabled']), true, false) . '> enabled</label>');
		self::form_row('Gemini URL context', '<label><input type="checkbox" name="gemini_url_context_enabled" value="1"' . checked(! empty($settings['gemini_url_context_enabled']), true, false) . '> enabled</label>');
		self::form_row('URL в prompt', '<label><input type="checkbox" name="gemini_use_source_url_in_prompt" value="1"' . checked(! empty($settings['gemini_use_source_url_in_prompt']), true, false) . '> enabled</label>');
		self::form_row('Требовать citations', '<label><input type="checkbox" name="gemini_require_citations" value="1"' . checked(! empty($settings['gemini_require_citations']), true, false) . '> enabled</label>');
		echo '</tbody></table></div>';
		submit_button('Сохранить настройки');
		echo '</form>';
		self::render_page_end();
	}

	public static function render_manual(): void {
		self::render_page_start('EPV3 Manual');
		self::render_notice();
		echo '<div class="epv3-card"><h2>Ручной импорт материала</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('epv3_manual_submit');
		echo '<input type="hidden" name="action" value="epv3_manual_submit">';
		echo '<table class="form-table"><tbody>';
		self::form_row('URL', '<input class="regular-text" name="original_url" required>');
		self::form_row('Язык исходника', '<input class="small-text" name="original_language" value="de">');
		self::form_row('Заголовок', '<input class="regular-text" name="original_title" required>');
		self::form_row('Кратко', '<textarea class="large-text" rows="3" name="original_excerpt"></textarea>');
		self::form_row('Текст', '<textarea class="large-text code" rows="10" name="original_content" required></textarea>');
		self::form_row('Изображение', '<input class="regular-text" name="source_image_url">');
		self::form_row('Приоритет', '<input class="small-text" type="number" min="1" max="100" name="priority" value="50">');
		echo '</tbody></table>';
		submit_button('Добавить в очередь');
		echo '</form></div>';
		self::render_page_end();
	}

	public static function render_review(): void {
		$id = (int) ($_GET['id'] ?? 0);
		$item = $id > 0 ? EPV3_Queue_Repository::find($id) : null;

		self::render_page_start('EPV3 Review');
		self::render_notice();

		if (! $item) {
			echo '<div class="epv3-card"><p>Выбери материал из <a href="' . esc_url(admin_url('admin.php?page=epv3-queue')) . '">очереди</a>.</p></div>';
			self::render_page_end();
			return;
		}

		echo '<div class="epv3-card"><h2>#' . (int) $item->id . ' ' . esc_html((string) $item->original_title) . '</h2>';
		echo '<p><strong>State:</strong> ' . esc_html((string) $item->state) . ' | <strong>Stage:</strong> ' . esc_html((string) $item->stage) . '</p>';
		echo '<p><strong>URL:</strong> <a href="' . esc_url((string) $item->original_url) . '" target="_blank" rel="noreferrer">' . esc_html((string) $item->original_url) . '</a></p>';
		echo '<p><strong>Ошибка:</strong> ' . esc_html((string) $item->error_message) . '</p>';
		echo '<div class="epv3-actions">';
		self::render_action_button('Вернуть в очередь', 'epv3_requeue_item&id=' . (int) $item->id, 'epv3_requeue_item_' . (int) $item->id);
		echo '</div></div>';

		self::payload_card('Context', (string) $item->context_payload);
		self::payload_card('Dossier', (string) $item->dossier_payload);
		self::payload_card('DE', (string) $item->de_payload);
		self::payload_card('UK', (string) $item->uk_payload);
		self::payload_card('EN', (string) $item->en_payload);
		self::payload_card('Publish', (string) $item->publish_payload);

		self::render_page_end();
	}

	public static function render_logs(): void {
		$runs = EPV3_Runs_Repository::get_items([
			'status' => 'failed',
			'limit' => 100,
		]);

		self::render_page_start('EPV3 Logs');
		self::render_notice();
		echo '<div class="epv3-card"><h2>Ошибочные запуски</h2>';
		self::render_runs_table($runs);
		echo '</div>';
		self::render_page_end();
	}

	public static function render_runs(): void {
		$runs = EPV3_Runs_Repository::get_items(['limit' => 150]);

		self::render_page_start('EPV3 Runs');
		self::render_notice();
		echo '<div class="epv3-card"><h2>Все запуски</h2>';
		self::render_runs_table($runs);
		echo '</div>';
		self::render_page_end();
	}

	public static function seed_test_item(): void {
		self::require_manage_options();
		check_admin_referer('epv3_seed_test_item');

		EPV3_Queue_Repository::add_item([
			'priority' => 50,
			'original_url' => 'https://example.com/news/demo-story',
			'original_title' => 'Deutschland prüft neue Regeln für kommunale Unterstützung',
			'original_excerpt' => 'Kommunen in Deutschland diskutieren über neue Unterstützungsregeln für Familien und lokale Dienste.',
			'original_content' => 'Kommunen in Deutschland prüfen neue Regeln für lokale Unterstützung. Dabei geht es um Familien, soziale Dienste und die praktische Wirkung neuer Entscheidungen vor Ort.',
			'source_image_url' => 'https://example.com/image.jpg',
			'original_language' => 'de',
		]);

		self::redirect_with_notice('epv3-dashboard', 'Тестовый материал добавлен.');
	}

	public static function run_tick(): void {
		self::require_manage_options();
		check_admin_referer('epv3_run_tick');
		EPV3_Orchestrator::tick();
		self::redirect_back('Один tick выполнен.');
	}

	public static function run_batch(): void {
		self::require_manage_options();
		check_admin_referer('epv3_run_batch');
		for ($i = 0; $i < 5; $i++) {
			EPV3_Orchestrator::tick();
		}
		self::redirect_back('Пять tick подряд выполнены.');
	}

	public static function save_settings(): void {
		self::require_manage_options();
		check_admin_referer('epv3_save_settings');

		$defaults = EPV3_Settings::defaults();
		$settings = [
			'mode' => sanitize_key((string) ($_POST['mode'] ?? $defaults['mode'])),
			'default_post_status' => sanitize_key((string) ($_POST['default_post_status'] ?? $defaults['default_post_status'])),
			'primary_language' => sanitize_text_field((string) ($_POST['primary_language'] ?? $defaults['primary_language'])),
			'publish_languages' => ['de', 'uk', 'en'],
			'collect_interval_minutes' => max(5, (int) ($_POST['collect_interval_minutes'] ?? $defaults['collect_interval_minutes'])),
			'process_interval_minutes' => max(1, (int) ($_POST['process_interval_minutes'] ?? $defaults['process_interval_minutes'])),
			'publish_interval_minutes' => max(1, (int) ($_POST['publish_interval_minutes'] ?? $defaults['publish_interval_minutes'])),
			'publish_delay_minutes' => max(1, (int) ($_POST['publish_delay_minutes'] ?? $defaults['publish_delay_minutes'])),
			'max_parallel_items' => max(1, min(10, (int) ($_POST['max_parallel_items'] ?? $defaults['max_parallel_items']))),
			'max_publish_per_tick' => max(1, min(10, (int) ($_POST['max_publish_per_tick'] ?? ($defaults['max_publish_per_tick'] ?? 3)))),
			'de_first' => ! empty($_POST['de_first']),
			'enrichment_min_sources' => max(1, min(10, (int) ($_POST['enrichment_min_sources'] ?? $defaults['enrichment_min_sources']))),
			'enrichment_target_sources' => max(1, min(10, (int) ($_POST['enrichment_target_sources'] ?? $defaults['enrichment_target_sources']))),
			'ai_budget_mode' => sanitize_key((string) ($_POST['ai_budget_mode'] ?? $defaults['ai_budget_mode'])),
			'ai_selection_strictness' => sanitize_key((string) ($_POST['ai_selection_strictness'] ?? $defaults['ai_selection_strictness'])),
			'queue_new_max_per_category' => (int) ($defaults['queue_new_max_per_category'] ?? 2),
			'queue_new_max_per_source' => (int) ($defaults['queue_new_max_per_source'] ?? 6),
			'rewrite_style' => sanitize_text_field((string) ($_POST['rewrite_style'] ?? $defaults['rewrite_style'])),
			'worker_timeout_seconds' => max(30, (int) ($_POST['worker_timeout_seconds'] ?? $defaults['worker_timeout_seconds'])),
			'ai_provider' => sanitize_text_field((string) ($_POST['ai_provider'] ?? $defaults['ai_provider'])),
			'ai_model' => sanitize_text_field((string) ($_POST['ai_model'] ?? $defaults['ai_model'])),
			'ai_fallback_provider' => sanitize_text_field((string) ($_POST['ai_fallback_provider'] ?? $defaults['ai_fallback_provider'])),
			'ai_fallback_model' => sanitize_text_field((string) ($_POST['ai_fallback_model'] ?? $defaults['ai_fallback_model'])),
			'gemini_search_grounding_enabled' => ! empty($_POST['gemini_search_grounding_enabled']),
			'gemini_url_context_enabled' => ! empty($_POST['gemini_url_context_enabled']),
			'gemini_use_source_url_in_prompt' => ! empty($_POST['gemini_use_source_url_in_prompt']),
			'gemini_require_citations' => ! empty($_POST['gemini_require_citations']),
		];

		update_option('epv3_settings', $settings, false);
		self::redirect_with_notice('epv3-settings', 'Настройки сохранены.');
	}

	public static function manual_submit(): void {
		self::require_manage_options();
		check_admin_referer('epv3_manual_submit');

		$id = EPV3_Queue_Repository::add_item([
			'priority' => max(1, min(100, (int) ($_POST['priority'] ?? 50))),
			'original_url' => esc_url_raw((string) ($_POST['original_url'] ?? '')),
			'original_language' => sanitize_text_field((string) ($_POST['original_language'] ?? '')),
			'original_title' => sanitize_text_field((string) ($_POST['original_title'] ?? '')),
			'original_excerpt' => sanitize_textarea_field((string) ($_POST['original_excerpt'] ?? '')),
			'original_content' => wp_kses_post((string) ($_POST['original_content'] ?? '')),
			'source_image_url' => esc_url_raw((string) ($_POST['source_image_url'] ?? '')),
		]);

		self::redirect_with_notice('epv3-review&id=' . $id, 'Материал добавлен в очередь.');
	}

	public static function requeue_item(): void {
		self::require_manage_options();
		$id = (int) ($_GET['id'] ?? 0);
		check_admin_referer('epv3_requeue_item_' . $id);

		if ($id > 0) {
			EPV3_Queue_Repository::update($id, [
				'state' => 'queued',
				'retry_after' => null,
				'error_code' => null,
				'error_message' => null,
			]);
		}

		self::redirect_with_notice('epv3-review&id=' . $id, 'Материал возвращён в очередь.');
	}

	private static function require_manage_options(): void {
		if (! current_user_can('manage_options')) {
			wp_die('Forbidden');
		}
	}

	private static function render_page_start(string $title): void {
		echo '<div class="wrap epv3-dashboard">';
		echo '<style>
			.epv3-dashboard .epv3-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:18px 0}
			.epv3-dashboard .epv3-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.epv3-dashboard .epv3-card h2{margin-top:0}
			.epv3-dashboard .epv3-card h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#50575e}
			.epv3-dashboard .epv3-metric{font-size:28px;font-weight:700;line-height:1.1}
			.epv3-dashboard .epv3-actions{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0 20px}
			.epv3-dashboard .epv3-filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:16px 0}
			.epv3-dashboard .widefat td pre{white-space:pre-wrap;max-height:380px;overflow:auto}
			.epv3-dashboard .epv3-code{background:#f6f7f7;border-radius:8px;padding:12px}
		</style>';
		echo '<h1>' . esc_html($title) . '</h1>';
	}

	private static function render_page_end(): void {
		echo '</div>';
	}

	private static function render_notice(): void {
		$message = sanitize_text_field((string) ($_GET['epv3_notice'] ?? ''));
		if ($message !== '') {
			echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
		}
	}

	private static function render_action_button(string $label, string $action, string $nonce_action, bool $primary = false): void {
		$url = wp_nonce_url(admin_url('admin-post.php?action=' . $action), $nonce_action);
		echo '<a class="button' . ($primary ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
	}

	private static function metric_card(string $label, string $value): void {
		echo '<div class="epv3-card"><h3>' . esc_html($label) . '</h3><div class="epv3-metric">' . wp_kses_post($value) . '</div></div>';
	}

	private static function render_queue_table(array $items, bool $with_actions): void {
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>State</th><th>Stage</th><th>Title</th><th>Updated</th><th>Quality</th>';
		if ($with_actions) {
			echo '<th>Действия</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ($items as $item) {
			$publish = json_decode((string) ($item->publish_payload ?? ''), true);
			$quality = is_array($publish['quality'] ?? null) ? $publish['quality'] : [];
			$title = '<a href="' . esc_url(admin_url('admin.php?page=epv3-review&id=' . (int) $item->id)) . '">' . esc_html((string) $item->original_title) . '</a>';
			echo '<tr>';
			echo '<td>' . (int) $item->id . '</td>';
			echo '<td>' . esc_html((string) $item->state) . '</td>';
			echo '<td>' . esc_html((string) $item->stage) . '</td>';
			echo '<td>' . $title . '</td>';
			echo '<td>' . esc_html((string) $item->updated_at) . '</td>';
			echo '<td>' . esc_html($quality !== [] ? self::quality_summary($quality) : 'n/a') . '</td>';
			if ($with_actions) {
				echo '<td><a class="button button-small" href="' . esc_url(admin_url('admin.php?page=epv3-review&id=' . (int) $item->id)) . '">Открыть</a> ';
				echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=epv3_requeue_item&id=' . (int) $item->id), 'epv3_requeue_item_' . (int) $item->id)) . '">В очередь</a></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_runs_table(array $runs): void {
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Job</th><th>Queue</th><th>Stage</th><th>Status</th><th>Started</th><th>Message</th></tr></thead><tbody>';
		foreach ($runs as $run) {
			echo '<tr>';
			echo '<td>' . (int) $run->id . '</td>';
			echo '<td>' . esc_html((string) $run->job_name) . '</td>';
			echo '<td>' . esc_html((string) $run->queue_id) . '</td>';
			echo '<td>' . esc_html((string) $run->stage) . '</td>';
			echo '<td>' . esc_html((string) $run->status) . '</td>';
			echo '<td>' . esc_html((string) $run->started_at) . '</td>';
			echo '<td>' . esc_html((string) $run->message) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function payload_card(string $title, string $json): void {
		$data = json_decode($json, true);
		echo '<div class="epv3-card"><h2>' . esc_html($title) . '</h2><pre class="epv3-code">' . esc_html(wp_json_encode(is_array($data) ? $data : $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
	}

	private static function form_row(string $label, string $field): void {
		echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . '</td></tr>';
	}

	private static function select(string $name, string $selected, array $options, string $empty_label = ''): string {
		$html = '<select name="' . esc_attr($name) . '">';
		if ($empty_label !== '') {
			$html .= '<option value="">' . esc_html($empty_label) . '</option>';
		}
		foreach ($options as $value => $label) {
			$html .= '<option value="' . esc_attr((string) $value) . '"' . selected($selected, (string) $value, false) . '>' . esc_html((string) $label) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	private static function state_options(): array {
		return [
			'queued' => 'queued',
			'processing' => 'processing',
			'retry' => 'retry',
			'ready_publish' => 'ready_publish',
			'published' => 'published',
		];
	}

	private static function stage_options(): array {
		$options = [];
		foreach (EPV3_Stage_Machine::ordered_stages() as $stage) {
			$options[$stage] = $stage;
		}
		return $options;
	}

	private static function quality_summary(array $quality): string {
		return sprintf(
			'CTX %d / SEO %d / G %d / T %d / R %d',
			(int) ($quality['context_score'] ?? 0),
			(int) ($quality['seo_score'] ?? 0),
			(int) ($quality['google_score'] ?? 0),
			(int) ($quality['translation_score'] ?? 0),
			(int) ($quality['release_score'] ?? 0)
		);
	}

	private static function redirect_with_notice(string $page, string $message): void {
		wp_safe_redirect(add_query_arg([
			'page' => $page,
			'epv3_notice' => rawurlencode($message),
		], admin_url('admin.php')));
		exit;
	}

	private static function redirect_back(string $message): void {
		$referer = wp_get_referer();
		$url = $referer ? add_query_arg('epv3_notice', rawurlencode($message), $referer) : add_query_arg([
			'page' => self::MENU_SLUG,
			'epv3_notice' => rawurlencode($message),
		], admin_url('admin.php'));
		wp_safe_redirect($url);
		exit;
	}
}
