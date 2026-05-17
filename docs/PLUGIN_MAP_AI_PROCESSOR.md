# AI Processor + Gates + Validators — карта (2026-05-14)

Read-only deep dive по PHP-слою EuroPulse Autopilot v21. Все file:line —
относительно ветки `review/plugin-audit`, коммит `199d7af`.

Версионные stamp'ы (bump → инвалидация stored payload'ов):
- `EPV2_AI_Processor::EDITORIAL_PROMPT_VERSION='2026-05-13-v22'` (ai-processor.php:15)
- `EPV2_Story_Card_Builder::STORY_CARD_PROMPT_VERSION='2026-05-11-v1'` (story-card-builder.php:29)
- `EPV2_AI_Response_Validator::VALIDATOR_VERSION='2026-05-12-v5'` (response-validator.php:14)

---

## 1. AI Processor — главные функции (`ai/class-epv2-ai-processor.php`, 10024 строки)

| Функция | Line | Что делает |
|---|---|---|
| `process_scheduled(force, ignore_retry_after)` | 17 | Entry-point из orchestrator. Acquire lock `process`, `next_item_for_processing`, single-item workflow, finish run. |
| `run_worker_stage(item, stage, existing)` | 2549 | Wraps `EPV2_Worker_Client::run_for_item`. Stamps `editorial_prompt_version` + preserves `_meta.story_card` через round-trip. Registers success/failure в `EPV2_Resilience_Manager`. |
| `normalize_existing_payload(payload, allow_expensive)` | 3583 | Per-request md5-cache (max 256). Cheap: contract_flags + fast routing + checklist. Expensive: `finalize_payload_for_queue` full recompute. |
| `payload_worker_warning_verdict(payload)` | 6563 | Soft-warning consumer. Threshold: `uk_filler_phrases ≥ 5`, `rewriter_fabricated_name ≥ 2 unique pairs`, `rewriter_explicit_date hard ≥ 1`, `rewriter_full_name soft ≥ 3` → `manual_review`. |
| `payload_blocker_strings(payload)` | 3522 | Extracts `_meta.blockers` (array of strings). |
| `payload_media_contract_passes(payload)` | 3566 | Delegates в `publish_ready_gate_media_contract_passes` (3354). Hard rule: Wikimedia/Pexels never pass; generated story-cover only через legacy. |
| `payload_is_publish_ready` / `payload_is_terminal_publish_ready` | 3498 / 3502 | Fast checklist vs строгая (substance + semantic consistency + real media). |
| `refresh_stage_checklist(payload)` | 7333 | Строит `_meta.stage_checklist`: `source_received, initial_analysis_done, context_saved, context_analysis_done, dossier_built, de_master_ready, uk_ready, en_ready, translations_ready, publish_finish_ready, ready_publish`. Clears `pipeline_stage=''` когда всё ready. |
| `recategorize_payload_from_de_master(payload)` | 7984 | После DE rewrite — `EPV2_Categorizer::resolve_for_payload` (Story Card primacy). |
| `worker_rebuild_payload_should_continue_to_publish_finish(payload)` | 7588 | Должен ли сразу в `publish_finish`? Требует: no blockers/context_reject, translations_ready, publish_finish_ready/de_master_viable. |
| `queue_required_stage(item_id, payload, stage, analysis, gate)` | 2247 | Persist payload + admin_notes c `workflow_step + workflow_step_status='pending'` + live_status (`stage_status_definition` 2442). |
| `transition_item_to_ready_publish` | 3411 | `EPV2_Publish_Gate::evaluate(context='ready_publish')` → mark `ready_publish` или false. |
| Inline stage attempt cap | 1361–1428 | **Pre-worker short-circuit** (2026-05-12): `stage_recent_attempts ≥ stage_attempt_limit_public` для `build_de_master/rebuild_bundle/publish_finish/publish_ready_gate` → terminal по importance (manual_review если `EPV2_Importance_Score::compute ≥ 40`, иначе rejected) + hard-terminal token. |

Background-repair (bridge_maintenance): `repair_stalled_translation_loops` 3768,
`repair_stalled_publish_finish_loops` 3850, `repair_stranded_rebuild_outputs` 3937,
`resolve_persisted_translation_manual_reviews` 4128, etc.

---

## 2. Pipeline stages

Per-stage cap = **2** attempts/2 ч (`workflow_stage_attempt_limit` queue.php:5232).
Canonical mapping `pipeline_stage ↔ workflow_step` в `canonical_workflow_step_from_stage` (2353):

| pipeline_stage | workflow_step | next on success | hard limit |
|---|---|---|---|
| `rebuild_bundle` | `build_de_master` | `translate_uk` / `publish_finish` | 2 (стандарт) + **6 hard** = `ready_review` (processor 558–586) |
| `translate_uk` | `translate_uk` | `translate_en` (`assert_stage_transition_ready` 2403) | 2 + `translation_no_progress_attempts ≥ 3-4` → terminal (706/889) |
| `translate_en` | `translate_en` | `publish_finish` | 2 |
| `translate_finish` | `translate_en` | `publish_finish` | 2 |
| `publish_finish` | `finalize_media → finalize_seo → publish_ready_gate` (`publish_finish_workflow_step` 2375) | `ready_publish` | 2 + `no_progress ≥ 2` / `retries.review_finish ≥ 2` / `workflow_step_attempts ≥ 4` → 30-min cooldown (1812) |

Special early terminations:
- `selection.decision ∈ {low,reject}` + stage=`rebuild_bundle` → immediate `rejected` без AI (528–553).
- Inline stage cap (≥2/час) → terminal pre-worker (1361–1428).

---

## 3. Worker Client (`core/class-epv2-worker-client.php`, 488 строк)

- Endpoints: `http://127.0.0.1:8765/process` (15), `/health` (14, 3-sec ping), `/analyze_story` (через story_card_builder).
- Auth header: `X-EPV2-Worker-Token` = `EPV2_Settings::worker_shared_secret()`.
- Timeout (305): translate_uk/translate_en → 300 s, иначе 240 s max (`worker_timeout_seconds`, default 120).
- Stage normalization (283): `rebuild_bundle/translate_finish/publish_finish/full → full_bundle` (PHP отправляет full_bundle, Python routes).
- `build_payload` (96) обогащает `existing_payload._meta`: `content_kind`, `enrichment` (`EPV2_Dossier_Enricher`), `thin_dossier_blocker` (132–149), `prior_coverage` (413, entity + category + 7d).
- `original_content` (187–260): richest of {clean_original, dossier_primary_content/excerpt} + story_card key_facts stitched если <200 слов.
- HTTP != 200 → `RuntimeException`; invalidate_availability_cache (319). Availability cache TTL = 30 s (`epv2_worker_available` transient).

---

## 4. Publish Gate (`publish/class-epv2-publish-gate.php`, 223 строки)

`evaluate(?item, payload, args)` (line 8). Args: `context`
(`ready_publish/publish/worker_terminal_outcome`), `force` (bypass schedule),
`manual_mode` НЕ из args — выводится из payload (`manual_override_present` 161:
`_meta.manual_mode/breaking/top_story` или `EPV2_Queue::item_has_publish_limit_override/priority_publish_override`).

Blockers (порядок проверки, lines 23–104):

1. `selection_<decision>` — selection.decision ∈ {low, reject} И **нет manual_override**.
2. `context_reject` (`payload_requires_terminal_context_reject`).
3. `stale_context` (191) — `context_analysis.reject_class='stale'` или primary_date > 48 ч.
4. `expired_live_angle` (`item_has_expired_live_angle`).
5. `missing_payload`.
6. `payload_blockers` — непустой `_meta.blockers`.
7. `stage_contract` — checklist ready_publish & translations_ready & publish_finish_ready & ! translations_deferred & semantic consistency.
8. `quality_contract` — `EPV2_Content_Kinds::payload_meets_quality`.
9. `enrichment_required` — `payload_meets_enrichment`.
10. `length_below_kind_minimum` — `payload_meets_de_length`.
11. `sources_below_kind_minimum` — `payload_meets_sources`.
12. `media_contract` (174) → `payload_media_contract_passes`.
13. `payload_contract` — `payload_is_publish_ready`.
14. `publish_not_due` (context='publish' без force; `publish_due` 214).
15. `already_published`.

**Manual override bypass работает ТОЛЬКО для selection_***. Content blockers
(stage/quality/length/sources/media/payload) НЕ bypass'ятся.

Callers (12): queue.php 1321/1814/2040/2421/3267/4874, ai-processor.php
1577/1664/3415/3465, admin.php 2312/2711.

---

## 5. Publisher (`publish/class-epv2-publisher.php`, 1723 строки)

`publish_item(item, status_override)` (215):

1. `EPV2_Review::ensure_payload` → opt. `repair_payload_languages` → `assert_multilingual_payload_ready` (236–245).
2. **Categories** (247–257): `normalize_categories` + `EPV2_Categorizer::expand_with_subcategories` (auto/it/technologie под wirtschaft; muenchen/bayern → +deutschland parent).
3. Story card → `sourceDossier['story_card']` (270–272) для media resolver.
4. `resolve_shared_publish_media_url` + `preflight_shared_publish_media_url` (274–275).
5. **Per-language loop** (288–393): `build_post_content` → `wp_insert_post` (313); `validate_featured_media` → `set_post_thumbnail` (348–369) + fallback resolve если invalid; persist `_epv2_source_url, _epv2_publish_media_url, _europulse_card_lead, _epv2_title_hash, _epv2_content_hash, _epv2_semantic_hash, _epv2_event_key, _epv2_primary_category, …`.
6. **Polylang sync** (396–406): `pll_set_post_language` per post + `pll_save_post_translations`.
7. **Categories assignment** (408–413): `wp_set_post_terms` через `term_ids_for_language`.
8. `EPV2_Post_Audit::repair_after_publish` (421), final-status apply, `mark_state('published')`, `reanchor_ready_publish_schedule_after_publish`, `cleanup_stale_queue_posts`.

`build_post_content` (875): `strip_unbacked_backlinks` → `strip_duplicate_lead`
→ **`EPV2_Source_Linker::link_attributions`** (inline attribution) →
`inject_inline_media_into_content` → `EPV2_Compliance::append_source_block`.

`strip_unbacked_backlinks` (812) удаляет EuroPulse-self-reference предложения
без `<a href>` (DE/UK/EN markers). Legit `<a>`-backed — keep.

Publish-error paths (56–132): stale → `retire_stale_publish_item`; media
blocker + circuit-breaker → `manual_review`, иначе invalidate media +
`force_pipeline_stage('publish_finish')` + `retry_process`; hard blocker →
`requeue_review_candidate_for_process` или `retry_process`.

---

## 6. AI Response Validator (`ai/class-epv2-ai-response-validator.php`, 2358 строк)

### Hallucination detectors

| Метод | Line | Что детектит | Threshold |
|---|---|---|---|
| `detect_invented_numbers` | 433 | 4+digit numbers с German separators + `X,Y Prozent/Euro/UAH/млрд`; word-form «zwei Milliarden» (word_num_map+scale_map). Skip 2020-2030 years. Не в primary/supporting/quotes/story_card.key_facts. | ≥2 → −35 score; **≥1 → publish_ready_gate HARD block** (processor 3254). |
| `detect_invented_quote_attributions` | 543 | `«…», VERB SpeakerName.` и обратный pattern. Surname (last word ≥4 chars) не в haystack. Skip role tokens (Minister, Kanzler, Polizei…). | **≥1 → HARD block** (3287); −15…−30 score. |
| `detect_cross_lang_title_substitution` | 624 | UK/EN title содержит attractor (`Зеленський/Putin`), но DE title и source не имеют equivalents. | **≥1 → HARD block** (3303); −35 score. |
| `detect_invented_publishers` | 699 | DE content упоминает publisher с attribution context (`laut/wie/berichtet/zufolge`), но host не в `_meta.source_dossier.urls`. Whitelist: Tagesschau, Spiegel, FAZ, Reuters, AFP, BBC, Guardian, CNN, NYT, УНІАН… | **≥1 → HARD block** (3295); −25/−12 score. |
| `source_dossier_thin_signal` | 792 | `primary content+excerpt < 300 chars` AND zero supporting ≥100 chars. | True + не breaking/top_story → **HARD block** (3270); −10 score. |

### editorial_quality (811) — score 100 baseline, pass=72

Major penalties: invented_numbers (−35/−8), invented_quotes (−30/−15),
invented_publishers (−25/−12), thin_signal (−10), title_substitution (−35),
`german_language_issue` (−28), `language_integrity_issue` cross-lang (−28),
`category_fit_issue` (−20), `supporting_context_issue` (−18),
`war_coverage_tone_issue` (1100; russian-military-target sympathy/false-symmetry,
−18), `filler_style_issue` (−16; per-lang, especially UK), `body_repeats_lead_without_depth` (−16),
template sections / bureaucratic tone / press-release tone (−10..−14), choppy rhythm (−10), `factual_density_issue` (−14), `headline_looks_generic` (−10).

`seo_quality` (1126) pass=78; `release_quality` (1197) pass=72; `google_preflight_quality` (1512).

Все 4 quality scores сравниваются с per-kind thresholds в Content Kinds (§8).

---

## 7. Categorizer (`classify/class-epv2-categorizer.php`, 849 строк)

| Метод | Line | Что |
|---|---|---|
| `resolve_for_payload(payload, title, content, seed)` | 71 | **Canonical resolver**. Story_card confidence ≥ 0.6 → trust. Иначе `detect_with_payload` → `refine_with_event_context`. Geography guard: international+non-DE+detected ∈ {deutschland,bayern,muenchen} → 'welt'. **Ukrainian-host guard** (88–102): bbc.com/ukrainian, pravda.com.ua, unian.ua, kyivpost, … → force 'ukraine'. |
| `detect_with_payload` | 23 | Confidence ≥ 0.6 → primary; иначе detect + geography guard. |
| `detect(title, content, source_bias)` | 209 | Keyword scoring + priority map (622): `ukraine=110, münchen=106, bayern=104, deutschland=102, leben-in-deutschland=100, sport=96, kultur=94, community=92, wirtschaft=90, world=88, politik=82, europa=80`. Sport context demotes bayern/muenchen. Fallback: 'welt' если bias∈{europa,welt}, иначе 'deutschland'. |
| `refine_with_event_context` | 693 | После dossier.event_context.kind — sport-finale-word требует sport-noun adjacency (ESC/music/show/judicial signals блокируют); community jobcenter → 'leben-in-deutschland'; community event/diaspora → 'community'. |
| `refine_with_story_card` | 676 | Card.category.primary если confidence ≥ 0.6. |
| `expand_with_subcategories(cats, title, content)` | 120 | Wirtschaft: keywords для auto (31377)/it (31383)/technologie (31389). Deutschland (14): muenchen (1)/bayern (12). Child без parent → adds parent. Called by publisher.php:256. |
| `looks_like_sport_context` | 822 | ESC/music/show signals → false; иначе sport keywords (bundesliga, dfb, halbfinale, fc bayern, …). |
| `is_clearly_non_german_international` | 50 | `geography.is_international=true & primary_country ∉ {DE/DEU/GERMANY}`. UA не считается. |

`canonical_slug` (837) → `EPV2_Taxonomy_Map::normalize_slug` (`world→welt`, `münchen→muenchen`).

---

## 8. Content Kinds (`ai/class-epv2-content-kinds.php`, 444 строки)

12 типов (27–38). `specs()` (57) thresholds:

| kind | de_min | src_min | enrich | quality / seo / release / google |
|---|---|---|---|---|
| breaking_alert | 80 | 1 | — | 90/90/75/75 |
| news_brief | 300 | 1 | — | 95/95/85/85 |
| news_article | 600 | 2 | **yes** | 100/100/90/90 |
| extended_news | 1500 | 3 | yes | 100/100/95/95 |
| analysis | 2500 | 4 | yes | 100/100/100/100 |
| feature | 2000 | 5 | yes | 100/100/100/100 |
| sport_result | 200 | 1 | — | 95/95/85/85 |
| obituary | 400 | 3 | yes | 100/100/95/95 |
| interview | 1000 | 1 | — | 100/100/95/95 |
| opinion | 600 | 1 | — | 100/100/95/95 |
| explainer | 1000 | 2 | yes | 100/100/95/95 |
| live_blog | 200 | 1 | — | 95/95/85/85 |

`detect_kind` (230) priority: obituary → sport_result → interview → opinion →
breaking_alert (live_ticker + fresh + <80 src words) → live_blog → feature →
explainer → analysis → extended_news (src≥2 & topics≥3 & people≥2) →
news_article (≥80 src_words, не brief) → news_brief.
worker `community_event → news_article`, `service_announcement → news_brief`.

Predicates: `payload_meets_de_length` (350), `payload_meets_sources` (358;
source_count + related/supporting), `payload_meets_quality` (370; все 4
порога), `payload_meets_enrichment` (387; enrichment.ran+related≥1 или
related+supporting≥1).

---

## 9. Importance Score (`ai/class-epv2-importance-score.php`, 132 строки)

`DEFAULT_THRESHOLD = 40` (28; bumped 30→40 2026-05-12). `compute(item, payload)` (36) 0–100:

1. Source priority/10 × 30 → 0–30 pts.
2. `item.story_score × 0.3` → 0–30 pts.
3. Story_card.publishable_estimate: high=+20, medium=+10.
4. `_meta.breaking` +15, `_meta.top_story` +10.
5. Multi-source: related≥2 → +10, ==1 → +5.
6. Story_card.editorial_match: match +10, borderline −15, reject_low_value −30.

`deserves_manual_review` (104): true если ≥ threshold.

Usage: inline stage-attempt cap terminal routing (processor 1366–1372);
quarantine rescue (queue.php:1847 — ingest≥40 OR card_estimate∈{high,medium}
отменяет selection_blocked).

---

## 10. Story Card (`ai/class-epv2-story-card-builder.php`, 232 строки)

`build(item, dossier)` (38) — `POST /analyze_story` (45-sec timeout). Worker
возвращает `{card, embedding}` (OpenAI embedding piggy-back, 97–107).

Когда: `process_scheduled` upfront (processor 130) — раз per item, кэшируется
в `_meta.story_card`. `build_from_array` (116) — для collector smart dedup.

Schema (worker contract):
```
v=1, success, prompt_version, built_at,
category: {primary, confidence, rationale},
kind: live_ticker|feature|analysis|interview|community_event|service_announcement|…,
publishable_estimate: high|medium|low,
editorial_match: match|borderline|reject_low_value, editorial_reason,
entities_people: [{name, role}], entities_organizations, entities_places,
geography: {is_international, primary_country},
key_facts, topics, tags,
rewrite: {length_profile: brief|standard|long},
media_search_terms,
semantic_embedding: {model, dim, vector, …}
```

Consumers:
- Categorizer: `detect_with_payload/resolve_for_payload/refine_with_story_card`.
- Media resolver: `card.media_search_terms` (publisher 267–272) ahead of title-regex.
- Worker client (worker-client 197–259): stitches key_facts+entities в `original_content` если <200 слов.
- Validators: `card.key_facts` входят в haystack для `detect_invented_numbers` (validator 449).
- Importance score: publishable_estimate, editorial_match.
- Tagger: `card.tags` primacy над downstream TF-IDF (validator 51–60).

`category_is_trusted(card, min_conf=0.6)` (150) — confidence-gate для категории.

---

## 11. Outcome decision matrix (после `run_worker_stage`)

Порядок проверки (processor 1444+):

1. `payload_has_ai_provider_failure` → `retry_process` (+30 min retry_after, schedule_retry). (1444–1460)
2. **`worker_outcome='ready_review' OR _meta.blockers ≠ [] OR soft_warning_verdict='manual_review'`** (1571–1722):
   - `EPV2_Publish_Gate::evaluate(context='worker_terminal_outcome')`.
   - `selection_publishable` → `ready_review` (manual_confirmation_required).
   - Иначе → `rejected`.
   - **Rescue** (E-fix, 1581–1602): single «too thin» blocker + (ingest≥40 OR card_estimate∈{high,medium}) → force selection_publishable → `ready_review`.
3. `worker_rebuild_payload_should_continue_to_publish_finish` → queue `publish_finish` (1723).
4. `payload_next_stage_from_cached_checklist` → queue next (1734).
5. `fast_transition_item_to_ready_publish || transition_item_to_ready_publish` → `ready_publish` через publish_gate.
6. `next_state_after_processing` → marked состояние.

---

## 12. Fragile points — кумулятивные gates

Item должен пройти **ВСЕ** для published:

1. **Pre-AI Story Card verdict** (processor 219–249, 372–441):
   - `editorial_match=reject_low_value` → rejected.
   - Post-card analyze_item rerun → `low/reject` (если не AI-endorsed `match+high/medium`) → rejected.
2. **Variant-D event-signature dedup** (265–321) — `EPV2_Deduplicator::is_event_duplicate`, time-tiered (0-30 min strict; 30 min-3 h unless breaking/new facts; 3-24 h как update; >24 h new cluster).
3. **`selection_score + publish_c`** per category (`EPV2_Budget_Manager`).
4. **Thin dossier pre-worker hard gate** (1302–1351) — source_count < sources_min для kind после enrichment → manual_review.
5. **Inline stage attempt cap** (1361–1428) — 2+ attempts/hour → terminal по importance.
6. **`quarantine_pathological_workflow_loops`** (queue 1756) — `workflow_step_attempts ≥ 2` (или ≥1 если selection.decision low) → quarantine (с rescue ingest≥40/card high-medium).
7. **`EPV2_Publish_Gate::evaluate`** — 15 blockers (§4); content-blockers НЕ bypass'ятся manual_mode.
8. **`publish_ready_gate_passes`** (processor 3220) — final hallucination re-check на момент publish:
   - `detect_invented_numbers ≥ 1` → block (3254).
   - `source_dossier_thin_signal=true` & не breaking/top → block (3270).
   - `detect_invented_quote_attributions ≥ 1` → block (3287).
   - `detect_invented_publishers ≥ 1` → block (3295).
   - `detect_cross_lang_title_substitution ≥ 1` → block (3303).
   - quality_meets_publish_gate (editorial/release/google) с per-kind thresholds (§8).
9. **`payload_worker_warning_verdict`** (6563) — soft worker warnings (см. §1) → manual_review.
10. **Publisher pre-flight** (publisher 274–275, 348–369): `resolve_shared_publish_media_url` + `preflight_shared_publish_media_url` + per-language `validate_featured_media`. Media blocker → media-repair retry path (§5).

---

## Appendix — load-bearing file:line

- Editorial prompt stamp: `ai/class-epv2-ai-processor.php:15`.
- Story Card prompt version: `ai/class-epv2-story-card-builder.php:29`.
- Validator version: `ai/class-epv2-ai-response-validator.php:14`.
- Importance threshold: `ai/class-epv2-importance-score.php:28`.
- Workflow stage attempt limit (=2): `queue/class-epv2-queue.php:5232`.
- Inline stage cap targets: `ai/class-epv2-ai-processor.php:1361`.
- Publish gate evaluate: `publish/class-epv2-publish-gate.php:8`.
- Publish ready gate hallucination block: `ai/class-epv2-ai-processor.php:3254–3306`.
- Story Card upfront primacy build: `ai/class-epv2-ai-processor.php:116–321`.
- Worker client send: `core/class-epv2-worker-client.php:303`.
- Strip unbacked backlinks: `publish/class-epv2-publisher.php:812`.
- Source linker entry: `publish/class-epv2-source-linker.php:52`.
- Categorizer canonical resolver: `classify/class-epv2-categorizer.php:71`.
- Subcategory expansion: `classify/class-epv2-categorizer.php:120`.
- Quarantine pathological loops: `queue/class-epv2-queue.php:1756`.
- Worker stage attempt limit table: `queue/class-epv2-queue.php:5232`.
- Worker run wrapper: `ai/class-epv2-ai-processor.php:2549`.

## R16 Extension hooks (added 2026-05-14)

| Hook | Type | Location | Signature |
|------|------|----------|-----------|
| `epv2_publish_gate_decision` | filter | publish-gate.php:122 | `($allowed, $item, $payload, $context)` |
| `epv2_category_publish_c` | filter | budget-manager.php:528 | `($publish_c, $category, $scorecard)` |
| `epv2_should_send_to_ai` | filter | budget-manager.php:425 | `($allow, $analysis, $verdict)` |
| `epv2_after_publish` | action | publisher.php:445 | `($post_id, $payload, $queue_id)` |
| `epv2_after_reject` | action | queue.php:2566 | `($id, $reason, $state)` |
| `epv2_pipeline_stalled` | action | core/class-epv2-alerts.php | `($duration_min, $ready_items)` |

## R13 EPV2_Alerts (added 2026-05-14)

`core/class-epv2-alerts.php`. `EPV2_Alerts::check_and_alert()` вызывается из bridge_maintenance каждые 180s. 4 triggers:
1. `check_pipeline_stall()` — 0 publishes 30 мин в active publish window
2. `check_ai_budget_hard_stop()` — `Budget_Manager::budget_state()['hard_stop']=true`
3. `check_worker_health()` — `wp_remote_get('http://127.0.0.1:8765/health')` 4xx/5xx
4. `check_orchestrator_heartbeat()` — R7 option `epv2_publish_thread_heartbeat` stale >180s

Each fire через `EPV2_Notifier::notify('alert', ...)` + 5-min transient dedup `epv2_alert_lock_{key}`.
