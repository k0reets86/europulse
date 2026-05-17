# Admin UI — визуальная карта (2026-05-14)

Working tree: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21/`. Все file:line ниже — внутри этого каталога.

Назначение: VISUAL-слой плагина (`PLUGIN_MAP_ADMIN.md` покрывает backend routing, эта карта — то, что видит и трогает оператор). Один монолит `includes/admin/class-epv2-admin.php` (4748 строк) — реальная "view + controller", без отдельных templates, без отдельного JS-файла. Весь HTML генерируется PHP-конкатенацией echo. Весь JS вшит inline через `wp_add_inline_script('jquery-core', …)` (`admin.php:83`). CSS отдельным enqueue нет — стили inline в каждом echo (см. §8).

## 0. Top-level menu — 10 страниц

Регистрация `menus()` `admin.php:86-100`. Top-cap = `manage_options`, sub-cap = `manage_europulse_autopilot` (`core/class-epv2-capabilities.php`).

| slug | callback file:line | заголовок | cap | icon / position |
|---|---|---|---|---|
| `epv2-dashboard` | `dashboard():102` | Обзор | `manage_europulse_autopilot` | `dashicons-rss`, pos=58 (parent) |
| `epv2-sources` | `sources():250` | Источники | `manage_europulse_autopilot` | — |
| `epv2-queue` | `queue():327` | Очередь | `manage_europulse_autopilot` | — |
| `epv2-settings` | `settings():2011` | Настройки | `manage_europulse_autopilot` | — |
| `epv2-manual` | `manual():2064` | Ручной режим | `manage_europulse_autopilot` | — |
| `epv2-review` | `review():1827` | Проверка материала | `manage_europulse_autopilot` | — |
| `epv2-schedule` | `schedule_page():2931` | Расписание | `manage_europulse_autopilot` | — |
| `epv2-telegram` | `telegram_page():2849` | Telegram | `manage_europulse_autopilot` | — |
| `epv2-logs` | `logs():2117` | Логи | `manage_europulse_autopilot` | — |
| `epv2-runs` | `runs():2127` | Запуски | `manage_europulse_autopilot` | — |

Top-level page sam cap = `manage_options` — но дублирующий первый submenu даёт ту же `epv2-dashboard` под `manage_europulse_autopilot`, так что effectively edit-doerator всё равно её открывает.

## 1. Dashboard (epv2-dashboard) — `dashboard():102-248`

Блоки сверху вниз:

1. **Заголовок + safeguard notice** (`:116-117`). `render_automation_safeguard_notice():473` показывает `notice notice-error` если `EPV2_Jobs::automation_safeguard_state()['active']` — оборот «Защита остановила автоматизацию» с reason + item_id + detected_at.
2. **Автоматический режим** (`:119-138`) — inline-card 980px max-width. Зелёный/янтарный лейбл для (paused, collect_paused). Кнопки: «Полная пауза» / «Пуск всё» / «Пуск без сбора» / «Пауза сбора» / «Возобновить сбор». Все через `admin-post.php?action=epv2_pause_automation|resume_automation|resume_automation_without_collect|pause_collect|resume_collect`, nonce-prefixed.
3. **Ручные сервисные действия** (`:139-148`) — кнопки `epv2_run_collect`, `epv2_run_process`, `epv2_run_publish`, `epv2_prune_queue`, `epv2_reset_stats`. Все одноразовые «не включают автоматику».
4. **Расписание публикаций** (`:153-184`) — preview-table 4-колоночная по `EPV2_Time_Planner::defaults()` windows. Read-only здесь, edit на `epv2-schedule`. Кнопка «Сбросить к архитектурному дефолту» — DELETE `epv2_settings.time_schedule_profile` (`reset_schedule_to_defaults():3048`).
5. **Понятные проблемы** (`:186-192`) — top-8 из `ep_epv2_log` где level∈(warning,error). Через `latest_human_issues():3255`.
6. **Прогресс сбора** (`:193-201`) — из `option epv2_collect_progress`, обновляется через ingest collector.
7. **Последний запуск сбора** (`:202-209`) — из `EPV2_Runs::latest('collect')`.
8. **Статистика** (`:210-224`) — фильтр по рубрике, 4-period table (день/неделя/месяц/год).
9. **AI вызовы за сегодня** (`:225-233`) — `EPV2_Stats::ai_usage_today()` по provider/model.
10. **Совокупный counters block** (`:234-247`).

Forms: `?stats_category=...` GET-only фильтр. Все mutation-кнопки через wp_nonce_url.

## 2. Sources (epv2-sources) — `sources():250-325`

Блоки:

- **Notice результата проверки** (`:270-281`) — readout из transient `epv2_source_test_{uid}`, ставится в `test_source()`.
- **Импорт рекомендованных** (`:282`) → `epv2_import_recommended_sources` → `EPV2_Source_Library::import()`.
- **Покрытие рубрик** (`:284-288`) — кол-во total/active per category.
- **Форма добавить/редактировать** (`:289-313`) — поля: name, type (rss/atom/google_news/scrape/telegram/facebook), url, language, category_bias, priority (1-10), fetch_interval (sec, min=300), risk_level, parse_rules_json, notes, is_active. Edit через `?edit_source=ID`.
- **Текущие источники** table (`:314-324`) — per-row buttons: Изменить / Вкл-выкл / Проверить / Удалить.

Permission: `require_source_capability()` для save/toggle/delete/test/import — sub-capability `edit_europulse_autopilot_sources` (отдельная от queue admin).

## 3. Queue (epv2-queue) — `queue():327-437` + lightweight blocks `:756-848`

**Самая сложная страница плагина.** Lightweight rendering = default (`:434` вызывает `queue_lightweight_blocks_html()`). Heavy path (`queue_full_blocks_html():686` → `queue_blocks_html():691`) не вызывается из этой страницы — он только для тестов; AJAX-snapshot тоже использует lightweight (`:467-468`).

### 3.1 7 блоков (operator-feedback W2.4)

| Блок (h2) | states источника | render | line |
|---|---|---|---|
| Новые | new, reserve, retry_process (filtered по ufs=new) | `render_light_queue_section('Новые', …)` | `:837` |
| В работе | processing_de OR row.id==active_id OR workflow_owner_token!='' | … `'active'` | `:838` |
| Готово к публикации | ufs ∈ (ready_publish, publishing); приоритет над active_id | … `'publish'` (с countdown timer) | `:839-842` |
| Готов к проверке | state=ready_review | … `'ready_review'` | `:843` |
| Ручная правка | state=manual_review | … `'manual_review'` | `:844` |
| Отклонённые | state ∈ (rejected, error, duplicate) | … `'rejected'` | `:845` |
| Опубликованные материалы | state=published, JOIN wp_posts ORDER BY post_date_gmt | … `'published'` | `:846` |

Router в `queue_lightweight_blocks_html():775-825`: ufs-first проверка перед active_id pointer (`:800-804`) — items с ready_publish ufs всегда попадают в «Готово», даже если token ещё не очищен. Limits: 120 live + 30 на каждый non-live block.

### 3.2 Top-bar формы (`:381-432`)

- **GET filter form** (`:381-400`) — state_filter, category_filter, orderby (id|state|priority|quality|seo|category|created_at|updated_at), order (asc|desc). State options в `queue_state_options():3879`.
- **Bulk delete всех выбранных** (`:401-406`) — hidden `ids_csv`, JS собирает `.epv2-queue-check:checked` через onclick, confirm prompt.
- **3 manual_review bulk forms** (`:410-429`) — видны ТОЛЬКО когда `state_filter=manual_review`:
  - «→ В публикацию» (`epv2_promote_manual_review`) → `promote_manual_review():2682`. Routes через `Publish_Gate::evaluate(force=true)`. Selection-only blockers (selection_reject|selection_low|selection_blocked|enrichment_required|sources_below_kind_minimum|publish_not_due|context_reject|stale_context) bypass'ятся → state=`ready_publish`. Real blockers (media/translations) → row остаётся в `manual_review` с counter в notice.
  - «↻ Перегенерить» (`epv2_reprocess_manual_review`) → `reprocess_manual_review():3066`. Drops `_meta.content_kind` + `_meta.story_card`, strip'ает `_system.workflow_*|retries|importance_*`, DELETE'ит `ep_epv2_runs` по `last_item_id`, mark_state=`new`.
  - «✕ Отклонить» (`epv2_reject_manual_review`) → `reject_manual_review():3138`. mark_state=`rejected` + `EPV2_Learning_Journal::record('manual_review_rejected', …)`.
- **Очистить очередь** (`:431`) → `clear_queue()`.
- **`#epv2-snapshot-stamp`** (`:432`) — JS-обновляемый timestamp "обновлено в HH:MM:SS".

### 3.3 Per-row action buttons (`render_light_queue_table():912-1024`)

15 колонок таблицы: checkbox / ID / Статус / Приоритет / Качество / SEO / Готовность / Медиа / Что не ок / Заголовок / Категории / URL / Создано / Обновлено-или-Готово-с / Действия.

Per-state buttons:

- **Все** — «Проверить» → `?page=epv2-review&item=ID` (no mutation).
- **ready_review** — «Готово к публикации» → `epv2_queue_to_publish` (`:976`).
- **ready_publish|retry_publish|ready_review** — «Опубликовать» → `epv2_publish_now` (`:979`).
- **rejected|manual_review** — «⚡ Опубликовать всё равно» → `epv2_force_publish` (`:992`, оранжевый background `#a04400`).
- **manual_review** — 4 stage-кнопки «📝 Title / 📰 Body / 🖼 Media / 🔍 SEO» (`:1005-1016`) → `epv2_regen_stage&stage=…`. Каждая bust'ит `_meta.stage_checklist|quality|seo_quality|release_quality|google_quality` после worker call (`regen_stage():2803-2809`).
- **published** — «Редактировать пост» → `wp-admin/post.php?action=edit&post=…` (`:994-998`).
- **Все** — «Удалить» → `epv2_delete_queue_item` (`:1018`).

Hint под кнопками (`queue_action_hint():3894`) — per-ufs Russian-language one-liner. Manual_review/rejected получают расширенные explanation через `manual_review_action_hint():3957` и `rejected_action_hint():4015` (читают `_system.last_stage_blocker`, `quarantine_reason`, `_meta.selection.decision/reasons`, `error_message` substring-match).

### 3.4 Per-block bulk delete (`render_queue_block_actions():738-755`)

Каждая section имеет свою форму с input id=`epv2-bulk-delete-ids-{type}` и data-block=`{type}` checkbox-фильтр. Для `rejected` дополнительно «Очистить» → `epv2_clear_rejected_queue`.

### 3.5 Countdown timers

| ID | data-target (ms) | data-interval (ms) | server-side | JS refresh |
|---|---|---|---|---|
| `#epv2-next-collect-countdown` | `next_collect * 1000` | `collect_interval_minutes * 60s * 1000` | `:371` | `renderCollectCountdown` (`:4440`), tick=1s |
| `#epv2-next-publish-countdown` | `next_publish * 1000` | `publish_interval_minutes * 60s * 1000` | `:711, :900` | `renderPublishCountdown` (`:4470`), tick=1s |

При diff≤0 timer показывает "00:00" и triggers `refreshQueueBlocks(true)` (`:4456, :4481`).

### 3.6 Auto-refresh AJAX

- Endpoint: `wp_ajax_epv2_queue_snapshot` (`:73`) → `queue_snapshot():439`.
- Nonce: `epv2_queue_snapshot`, cap `manage_europulse_autopilot`.
- URL constructed: `wp_nonce_url(admin-ajax.php?action=epv2_queue_snapshot, 'epv2_queue_snapshot')` + `html_entity_decode` (`:4356`) — иначе `&amp;_wpnonce` парсится как `amp;_wpnonce` и сервер возвращает 403.
- Cache: `transient epv2_queue_snapshot_{uid}_{md5(params)}` TTL=3s (`:9 QUEUE_SNAPSHOT_CACHE_TTL`).
- Guard: `transient epv2_queue_snapshot_guard_{uid}` cooldown=5s (`:18 QUEUE_SNAPSHOT_REQUEST_COOLDOWN`).
- Refresh interval (JS): 10000ms (`:17 QUEUE_SNAPSHOT_REFRESH_MS`, bumped с 2500 после operator-feedback — items «перепрыгивали» между секциями).
- Initial AJAX delay: 4000ms (`:4584-4588`) — даёт server render stabilize до первого AJAX overwrite.
- visibilitychange listener (`:4590-4594`) — refresh при возврате tab'а в foreground.

Returns `{html, sections: {new, active, publish, manual_review, rejected, published}, next_collect, next_publish, collect_running, throttled}`. JS apply'ит section-by-section через `outerHTML` (`:4506-4513`), fallback на `queueBlocks.innerHTML = response.data.html` если sections отсутствуют.

### 3.7 Queue notices

`?queue_notice=…` query param + `transient epv2_queue_notice_{uid}` для details:

| notice | сообщение | severity |
|---|---|---|
| `not_publish_ready` | Материал не дотянул до полного publish-grade | error |
| `publish_blocked` | Публикация остановлена: не прошёл quality gate | error |
| `published_now` | Материал опубликован вручную | success |
| `publish_failed` | детали из transient | error |

`render_automation_safeguard_notice():473` — отдельный notice notice-error для safeguard-state.

## 4. Settings (epv2-settings) — `settings():2011-2062`

Один большой `<form>` POST → `admin-post.php?action=epv2_save_settings` (`save_settings():2137-2145`). После save: `EPV2_Settings::set_all($_POST)` + `EPV2_Jobs::clear_scheduled()` + `EPV2_Jobs::schedule_recurring()`. Redirect → `?updated=1`. **Никакой явной валидации в обработчике** — всё через `EPV2_Settings::set_all`.

### 4.1 Поля (45+ rows)

Условно-логические группы (визуально NOT grouped, идут одной table.form-table):

- **Режим работы**: mode (manual/semi/auto), default_post_status (draft/pending/publish).
- **AI providers**: ai_provider, ai_model, ai_fallback_provider, ai_fallback_model, rewrite_style (strict/analytic/lively).
- **Gemini-specific toggles**: gemini_search_grounding_enabled, gemini_url_context_enabled, gemini_use_source_url_in_prompt, gemini_require_citations.
- **API ключи (AES-256-CBC encrypted)**: ai_keys[gemini], ai_keys[deepseek], ai_keys[openai], ai_keys[anthropic], image_keys[pexels], image_keys[unsplash], worker_shared_secret. Все через `secret_input():3211` — `<input type="password" autocomplete="new-password">` со значением="" и placeholder "Сохранён — введите новый для замены" (если уже есть) или "Вставьте ключ".
- **Интервалы**: collect_interval_minutes, process_interval_minutes (рекомендация 5 как backstop), publish_interval_minutes (рекомендация 5).
- **Бюджеты**: max_collect_per_category, ai_budget_mode (normal/economy/critical), ai_selection_strictness (low/medium/high), ai_daily_request_soft_limit, ai_daily_token_soft_limit, daily_publish_target, enforce_daily_publish_target.
- **Worker config**: worker_mode (disabled/cli), worker_python_bin, worker_cli_command, worker_src_dir, worker_timeout_seconds, worker_shared_secret.
- **Retention/caps**: queue_retention_days, queue_new_ttl_hours, queue_new_max_per_category, queue_new_max_per_source.
- **Prompts** (4 textareas): prompts[auto_rewrite], prompts[analysis_rewrite], prompts[manual_rewrite], prompts[seo_refine].

JS sync (`:4384-4406`): при смене provider — модель selector перестраивается из `epv2ModelMap`. Usage hint обновляется live из `epv2UsageMap` (вызовы/токены сегодня).

### 4.2 Скрытые/conditional поля

- Telegram (token/chat_id/include_warnings/include_info) ВНЕ этой страницы — отдельная `epv2-telegram`.
- Schedule (time_schedule_profile) ВНЕ — отдельная `epv2-schedule`.

Defaults определяются в `core/class-epv2-settings.php`. Validation минимальная: number-type через `(int)`, secret через trim + only-if-non-empty (см. `EPV2_Settings::set_all`).

## 5. Schedule (epv2-schedule) — `schedule_page():2931-2982`

Per-window 8-row table editor (defaults — 8 windows из `EPV2_Time_Planner::defaults()`, в карте `PLUGIN_MAP_ADMIN.md` упомянуто как «7-window», но defaults на самом деле 8 включая ночное).

Каждое окно: start (HH:MM regex), end (HH:MM), mode (sanitize_key), timer_minutes (0-60, 0=пауза), collect_minutes (CSV "0,30").

Save (`save_schedule():2988-3041`) восстанавливает `publish_minutes` из timer step (`for $m=0; $m<60; $m+=$timer_min`). При пустом окне (start='' OR end='' OR mode='') row дропается. Если ни одно валидное окно — оставляет старое расписание + error notice.

Reset to defaults — `reset_schedule_to_defaults():3048` (delete key из option, defaults takes over). Кнопка confirm prompt.

## 6. Manual (epv2-manual) — `manual():2064-2113`

Manual rewrite UI для оператора:

- URL field + кнопка «Импортировать по URL» (`manual_op=import_url`).
- Категории checkboxes + style select + editorial flags (breaking/top_story).
- DE/UK/EN blocks (заголовок/лид/текст), каждый с собственной AI-кнопкой `manual_op=rewrite_{lang}_{field}`.
- «Собрать или обновить DE master» (`generate_master`) — берёт лучший заполненный язык, генерит DE.
- Media: featured + inline (Media Library picker через `epv2-media-pick` JS).
- SEO block (`draft[seo][…]`) с «Сгенерировать и SEO-оптимизировать AI» (`seo_optimize`).
- Bottom controls: Сохранить / Очистить / **Сгенерировать и отправить в автоматику** (`to_review`).

Handler `manual_submit():2454-2540` — switch по `$op`. Каждая ветка вызывает `EPV2_Manual_Mode::*` и ставит transient notice `epv2_manual_notice_{uid}`.

## 7. Review (epv2-review?item=ID) — `review():1827-2009`

Per-item review form. Открывается из «Проверить» в queue (`:974`).

Sections сверху вниз:

1. **Quality notices** (`:1849-1910`) — 4 баджа: editorial quality, SEO quality, release quality, Google preflight. Каждый — `notice notice-info|notice-warning` с score/100 + warnings list per-language.
2. **Blockers list** (`:1911-1918`) — `notice notice-error` с `review_blockers():3785`.
3. **Context memory panel** (`render_context_memory_panel():4166-4211`) — summary/event/venue/datetime/snippet + participants/entities/locations/dates/money/theses/facts/search_terms. Translation-on-the-fly через AI (`context_memory_for_russian_display():4213` + `translate_context_memory_to_russian():4253`) если контент не в кириллице. Cached в `transient epv2_context_memory_ru_{md5}` TTL=DAY.
4. **Main form** (`:1920-1955`) — categories, style, editorial flags, featured media, inline media (textarea, one URL per line).
5. **Per-language sections** (`:1932-1948`) — title/lead/body/media/SEO для каждого языка в publish_languages. Для DE дополнительно AI-кнопки «AI ↺ Заголовок / Лид / Текст / Медиа / SEO» (`regen_block_btn():3586`) — синие #1d4ed8, AJAX через `epv2_regen_block`.
6. **Submit row** (`:1950-1953`) — «Сохранить черновой пакет» + «Сохранить и подготовить к публикации» + (если ready_review/ready_publish) «Опубликовать».
7. **Preview** (`:1956-2007`) — media + per-language full-render с wpautop. Inline images grid `grid-template-columns:repeat(auto-fit,minmax(220px,1fr))`.

AJAX endpoint `epv2_regen_block` (`regen_block():4665-4715`) — per-block worker call:
- Nonce: `epv2_regen_block_{item_id}`, cap `manage_europulse_autopilot`.
- Allowed blocks: `title|lead|body|media|seo`.
- Возвращает только relevant fields через `extract_regen_output():4720`. JS обновляет conkretные textarea/input по ID (`#epv2-title-de`, etc.).

Notices: `?saved=1` / `?regenerated=1` / transient `epv2_review_notice_{uid}`.

## 8. CSS — inline-only

**Никаких enqueue_style вызовов вообще** в admin.php. Все стили — inline через `style="..."` атрибуты внутри echo'ов. Brand colors используются consistently:

- **Зелёный** (success/published/active): `#15803d`, `#166534`, `#dcfce7`, `#dbeafe`.
- **Янтарный** (warning/paused): `#b45309`, `#92400e`, `#fef3c7`, `#8a6d3b`.
- **Красный** (error/rejected): `#991b1b`, `#fee2e2`, `#b32d2e`, `#c62828`, `#fff1f2`.
- **Голубой** (info/SEO): `#075985`, `#1d4ed8`, `#e0f2fe`, `#bfdbfe`, `#eff6ff`.
- **Серый** (muted/empty): `#646970`, `#50575e`, `#8c8f94`, `#dcdcde`, `#f3f4f6`, `#f6f7f7`.

Layout: WP core classes `wrap`, `widefat striped`, `form-table`, `button button-primary|button-small`. Custom: `epv2-queue-table-wrap` (max-height:420px, overflow:auto), `epv2-queue-check` data-block-filtered, `epv2-queue-archive` `<details>` для скрытых архивных. Cards — inline `background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:980px`. Badges — pill style `border-radius:999px;font-size:11px;font-weight:700`.

## 9. JS — inline-only

`enqueue_assets():78-84` — единственный enqueue: `wp_enqueue_media()` (Media Library picker) + `wp_add_inline_script('jquery-core', self::admin_script())`. Никакого отдельного .js файла.

Script content в `admin_script():4346-4648`. Зависимость: jQuery (через core handle). Behaviors:

- **AI provider/model sync** (`syncModels`, `syncUsage`, `:4367-4406`).
- **Media picker** (`.epv2-media-pick`, `:4407-4422`) — wp.media frame, multi=false, type=image|video.
- **Regen URL builder** (`.epv2-regen-link`, `:4423-4432`) — обновляет href с `style=` параметром перед клик.
- **Countdown timers** (`:4440-4498`) — 1s tick для collect/publish.
- **Queue snapshot AJAX** (`refreshQueueBlocks`, `:4530-4577`) — 10s interval + visibilitychange listener.
- **Per-block regen** (`.epv2-regen-block-btn`, `:4600-4640`) — POST `epv2_regen_block`, синхронно обновляет input#epv2-{title|lead|body|media|seo}-de.

Confirmation dialogs (inline-onclick): «Удалить выбранные материалы?», «Сбросить сегодняшние счётчики?», «Опубликовать материал в обход редакторского фильтра?», «Перегенерить заново?», «Очистить очередь?», и др. — все нативные `confirm()`.

## 10. AJAX endpoints (admin-ajax.php) — отдельно от REST

Только 2 экшна:

| action | handler | nonce | cap | назначение |
|---|---|---|---|---|
| `epv2_queue_snapshot` | `queue_snapshot():439` | `epv2_queue_snapshot` | `manage_europulse_autopilot` | live-refresh queue blocks |
| `epv2_regen_block` | `regen_block():4665` | `epv2_regen_block_{id}` | `manage_europulse_autopilot` | per-block AI regenerate |

Всё остальное — `admin_post_*` (sync POST/GET с redirect) или REST API в `api/class-epv2-rest.php` (см. `PLUGIN_MAP_ADMIN.md` §3).

## 11. Notice / Alert system

3 transient-based notice channels:

| transient | source | reader | TTL |
|---|---|---|---|
| `epv2_queue_notice_{uid}` | publish_now/force_publish handlers | `queue():340-355` reads via `?queue_notice=…` | 5 min |
| `epv2_review_notice_{uid}` | save_review/regenerate_field/publish_now-from-review | `review():1842-1845` | 5 min |
| `epv2_manual_notice_{uid}` | manual_submit handlers | `manual():2066-2074` | 5 min |
| `epv2_admin_notice` | bulk-promote/reject/reprocess, save_schedule, save/test_telegram, regen_stage | `telegram_page():2857`, `schedule_page():2937` | 30-60 sec |
| `epv2_source_test_{uid}` | test_source handler | `sources():271-280` | per call |
| `epv2_context_memory_ru_{md5}` | translate_context_memory_to_russian | render_context_memory_panel | 1 day |

Stale notice cleanup: notices читаются с `delete_transient()` сразу после первого render — single-shot.

State-aware message suppression: queue table colors per-state. Per-block `render_light_queue_table():955-957` гасит metric badges и state-label для manual_review/rejected/error/duplicate/published — focus переключается на action-hint вместо устаревших scores.

## 12. Per-state UI behavior

Что видит оператор для каждого state в столбце «Статус» + «Действия» + hint:

| state (raw) | ufs | label | действия | hint (см. `queue_action_hint():3894` + variants) |
|---|---|---|---|---|
| new, reserve, retry_process | new | "Новый" | Проверить / Удалить | "Материал ждёт своей очереди и останется в списке новых, пока не освободится единственный рабочий слот" |
| processing_de OR active_id OR token!='' | active | "В работе · NN% · stage_label" | Проверить / Удалить | "Сейчас это единственный активный материал. Система должна довести его до готовности к публикации, прежде чем взять следующий" |
| ready_review | manual | "Ручная проверка" | Проверить / Готово к публикации / Опубликовать / Удалить | "Worker собрал, нужен apply" (в section title) |
| ready_publish, retry_publish | ready_publish | "Готов к публикации" или "Готов к публикации до HH:MM" | Проверить / Опубликовать / Удалить | "Материал полностью готов и ждёт ближайшего автоматического цикла публикации" |
| publishing | publishing | "Публикуется" | Проверить / Опубликовать / Удалить | same as ready_publish |
| manual_review | manual | "Ручная проверка" | Проверить / ⚡Опубликовать всё равно / 4×regen-stage / Удалить | per-blocker explanation из `manual_review_action_hint():3957`; например "Нет подходящего фото — открой материал и приложи фото вручную" |
| rejected | rejected | "Отклонён" | Проверить / ⚡Опубликовать всё равно / Удалить | per-rejection explanation из `rejected_action_hint():4015`; например "Дубликат уже опубликованного материала. Оставь как есть" |
| duplicate | duplicate | "Дубликат" | Проверить / Удалить | "Это дубль; публикация отключена" |
| error | error | "Ошибка" | Проверить / Удалить | "Это технический terminal-state. Материал остановлен из-за ошибки обработки, а не отклонён редакционно" |
| published | published | "Опубликован" | Проверить / Редактировать пост / Удалить | "Материал уже опубликован. 'Редактировать пост' открывает его в редакторе WordPress, а 'Удалить новость' отправляет опубликованные посты в корзину" |

Stage labels внутри "В работе · …" — `queue_light_progress_stage_label():1548`: claimed → "материал взят в работу", build_de_master → "собирает DE master", translate_uk → "готовит украинскую версию", translate_en → "готовит английскую версию", publish_ready_gate → "финальная publish-ready проверка", и т.д.

Stalled detection: `queue_light_item_is_stalled():1485` — если active item не двигается, label меняется на "Остановлено защитой · 0% · обработка прервана".

## 13. Accessibility / mobile

**Очень слабо.** Дизайн ориентирован на desktop wp-admin:

- Все form-table используют WP default styles — mobile WP autoflow эти `<th>`/`<td>` стакает, но queue tables (`widefat striped` с 15 колонок) горизонтально не помещаются на телефон — будет scroll или ulptra-wide overflow.
- Кнопки `button button-small` ~28px — borderline-touch-friendly, нет minimum 44pt enforcement.
- Inline checkbox handlers (`onclick="..."`) — no keyboard alternative для bulk select.
- Confirm prompts — нативные JS `confirm()`, не accessible-styled modal.
- Цвета баджей (red/amber/green) — нет text-alternative для colorblind пользователей; passing `title` attr содержит warning list, но screen-reader experience не testing'овался.
- `wp_enqueue_media()` — works on mobile через WP responsive media uploader, но preview grids 220px min-column wide.

Touch UX: per-row checkbox + 4-7 кнопок в action column = очень плотно на телефоне, easy mis-tap. Force-publish (`⚡`) — оранжевая кнопка с confirm = защита от случайных кликов.

## 14. Fragile points — interaction risks

1. **Stale snapshot lag**. Server cache TTL=3s + JS refresh=10s + request cooldown=5s. Между AJAX-refresh items могут мелькать (item state меняется на сервере, но UI отрисовывает кэш до 13s старый). После operator action (force_publish, regen_stage) первый refresh из кэша покажет старый row pre-action — раздражает оператора, провоцирует double-click. Mitigation: `applySnapshotSections` обновляет section outerHTML — все row IDs внутри секции свежие, но если row сменил секцию, он временно в обеих/ни в одной.

2. **active_id vs workflow_owner_token desync**. `:564-567` и `:815-817` проверяют ОБА сигнала: `processing_de OR active_id pointer OR token`. Между orchestrator-тиками active_id pointer может быть кратко 0 (между mark_state и `sync_active_automation_item`). Token держится дольше (set при claim, cleared при terminal). Если token остался на ready_publish row из-за нестандартного transition, ufs-first проверка (`:800-804`) спасает — items с ready_publish ufs ВСЕГДА в Publish секции независимо от token. Но если ufs==='new' AND token!='', item попадает в «В работе» когда он там не работает.

3. **Race между bulk action и AJAX refresh**. Bulk promote/reject/reprocess делают N×`mark_state` + N×`update_fields` + DELETE из runs table. Если AJAX refresh приходит mid-loop, JS получит partial snapshot и section обновится с половиной items уже-promoted, половиной ещё-manual_review. Не fatal (next refresh выправит), но визуально "items пропадают и появляются обратно" в течение 10s.

4. **Force-publish silent route to manual_review**. Если оператор кликает «⚡ Опубликовать всё равно» на rejected row, но есть real content blockers (нет media), row переходит в manual_review с error_message. Redirect notice = `force_publish_pending`, но в queue handler (`:339-355`) этот notice key НЕ обрабатывается — оператор видит row в manual_review без обратной связи о том, что произошло. Только error_message колонка показывает.

5. **Confirm-on-multiple-buttons**. 4 stage-regen-кнопки + Force-publish + Delete + bulk-delete — каждая со своим `confirm()`. На телефоне alert stack может накладываться, на ноуте — easy mis-confirm после нескольких кликов.

6. **Settings encryption indicator weak**. `secret_input():3211` ставит `<p class="description">Ключ сохранён и не выводится</p>` — но это всё. Нет визуальной разницы между «ключ есть и валидный» и «ключ есть и не работает». Тест-кнопка только для Telegram, не для AI-keys.

7. **Snapshot URL nonce escaping**. `:4356` `html_entity_decode(wp_nonce_url(…))` — fix для известного бага. При regression `&amp;` parser-issue вернётся и все snapshot 403'нутся.

8. **Inline JS = no minification, no CSP**. Весь JS вшит inline в jquery handle — Content-Security-Policy с `unsafe-inline` запретом сломает страницу полностью. Plugin не worker'у не CSP-friendly без рефакторинга.

9. **Queue actions hint ordering** (`:3913-3917`). Specific-states-first matters: order правок ranking путал previously-recoverable rows с «останутся в списке новых» message, пока row сидит в «Готов к публикации». Сейчас порядок: explicit `manual_review` → `rejected` → `ready_publish|publishing` → `new|active` → recoverable → default. Любая reorder сломает per-row consistency.

10. **No CSV/JSON export**. Queue таблица — всё, что есть. Если оператор хочет audit-trail по rejected за день, нужно копировать руками или лезть в `wp_epv2_log` через CLI/phpMyAdmin. Logs/Runs страницы — read-only без фильтров, без поиска, без pagination (LIMIT 100).

---

## Gaps в UI (honest list)

- **Нет inline-редактирования** queue table. Все правки — через Review page или через прямой DB-edit.
- **Нет search bar** на Logs / Runs / Queue. Только filter dropdowns по state/category.
- **Нет pagination** на Logs (LIMIT 100), Runs (LIMIT 100), Queue (LIMIT 80, no offset).
- **Нет undo** для delete/reject — items уходят сразу.
- **Нет audit log UI** для operator actions (кто когда что нажал). `EPV2_Learning_Journal::record` пишет в БД, но UI чтения нет.
- **Нет breaking news fast-track UI** — `_meta.breaking` ставится только из Review page editorial flags, не из queue list.
- **Telegram test** — есть. AI key test — нет. Worker availability test — только при regen_block call.
- **Sources test** возвращает result в transient, нет inline preview.

## Decorative vs actionable in full-auto

Operator stated workflow = full autonomous. UI controls которые становятся декоративными при auto-run:

- «Собрать кандидатов один раз», «Обработать очередь один раз», «Проверить публикацию один раз» (dashboard `:143-145`) — никогда не понадобятся, autopilot делает сам каждые N минут.
- «Сбросить счётчики» (`:147`) — статистика-only, не влияет на logic.
- «Сохранить черновой пакет» / «Подготовить к публикации» / Per-language regen-в-Review (`:1942-1946`) — оператор не зайдёт в Review в auto-режиме.
- Manual page целиком — для ad-hoc ручных публикаций, не для daily auto-flow.
- Per-stage 4-кнопки на manual_review (`📝/📰/🖼/🔍`) — нужны редко (когда auto-pipeline reach manual_review). Bulk regen-promote-reject — массовый recover from backlog.

Actionable в full-auto: dashboard pause/resume, queue manual_review actions (Force-publish / Regen-stage / Bulk-promote), Sources management, Settings tuning, Schedule editor, Telegram alert config. Остальное — diagnostic/manual-fallback.
