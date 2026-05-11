# EuroPulse Pipeline Tests — Phase A scaffolding

**Status**: Skeleton created 2026-05-11. Phase A goal per [SSOT design](../docs/architecture-2026-05-11-ssot-design.md) §3 Phase A.

## Цель Phase A

Тесты должны проверять что **текущий** pipeline корректно маршрутизирует 25 типичных edge cases. Это **baseline** перед SSOT refactor — чтобы знать какое поведение не сломать.

Тесты должны:
1. Работать БЕЗ worker (mock `/analyze_story` responses)
2. Быть deterministic (нет AI calls)
3. Покрывать каждую decision point из docs/architecture-2026-05-11-decision-map.md
4. Использовать общую assertion library

## Структура

```
tests/
├── README.md                  — этот файл
├── run.sh                     — entry point (wp eval-file)
├── fixtures/                  — 25 synthetic queue items JSON
│   ├── 01_news_priority.json  — high-priority politik news
│   ├── 02_news_review.json    — review-tier news
│   ├── 03_promo_anzeige.json  — Anzeige promotional (should hard-reject)
│   ├── 04_celebrity.json      — Sandra Bullock case
│   ├── 05_sport_excluded.json — Handball/NBA (excluded by calibration)
│   ├── 06_dup_event.json      — event-signature duplicate
│   ├── ...                    — 25 total
├── lib/
│   ├── pipeline_contracts.php — assertion library
│   │   ├── assert_state($id, $expected_state)
│   │   ├── assert_payload_invariant($id, $key, $value)
│   │   ├── assert_workflow_step($id, $step)
│   │   ├── assert_quarantine_reason($id, $reason)
│   │   ├── assert_story_card_present($id)
│   │   └── assert_no_active_token($id)
│   ├── fixture_loader.php    — load + persist fixtures
│   ├── mock_worker.php       — stub Story_Card_Builder + Worker_Client
│   └── test_helpers.php      — common queue manipulation
├── suites/
│   ├── state_machine_test.php       — exercise ALLOWED_TRANSITIONS
│   ├── selection_test.php           — single-pass heuristic verification
│   ├── category_authority_test.php  — story_card primacy + geo guard
│   ├── publish_gate_test.php        — 9-check evaluation
│   ├── maintenance_handlers_test.php— 14 handlers ordering
│   └── editorial_calibration_test.php — per-rubric stop-lists
```

## Acceptance criteria

Phase A — все 25 fixtures должны route'иться corectly через current code (baseline before any change). Mismatches задокументировать но НЕ исправлять — это Phase B/C/D работа.

## 25 fixtures roadmap

Selection class (5 fixtures):
- [ ] 01 priority news (politik, score 70+)
- [ ] 02 review news (score 40-50)
- [ ] 03 promotional Anzeige
- [ ] 04 celebrity (low editorial value)
- [ ] 05 hard-reject pattern (sport_live_fixture)

Category class (5 fixtures):
- [ ] 06 story_card override keyword
- [ ] 07 geography guard (international non-DE)
- [ ] 08 event-context refinement
- [ ] 09 community_event kind mapping
- [ ] 10 low-confidence story_card (use heuristic)

Editorial class (4 fixtures):
- [ ] 11 editorial_match=match
- [ ] 12 editorial_match=borderline
- [ ] 13 editorial_match=reject_low_value
- [ ] 14 editorial_match=NULL (worker fail — should go to manual_review fast)

Media class (3 fixtures):
- [ ] 15 wiki/pexels (must block)
- [ ] 16 source_dossier_image (pass)
- [ ] 17 missing media (manual_review)

Quality class (3 fixtures):
- [ ] 18 quality 100 + AI endorsed → ready_publish
- [ ] 19 quality 75 + AI endorsed match+high → relax to 70 → ready_publish
- [ ] 20 quality 99 oscillation case

Dedup class (2 fixtures):
- [ ] 21 hash duplicate (drop)
- [ ] 22 event-signature variant-D

State machine (3 fixtures):
- [ ] 23 stale heartbeat recovery
- [ ] 24 retry exhausted → manual_review
- [ ] 25 manual_review → operator publish

## Tests run order

```bash
cd /var/www/europulse/public
sudo -u www-data wp eval-file /root/projects/europulse/tests/run.sh
```

## Implementation notes

- Use raw SQL inserts для fixtures (bypass collector entirely)
- Mock Story_Card_Builder с deterministic responses per fixture metadata
- Run with `EPV2_TEST_MODE=1` env to skip side effects (no real publishing)
- Each suite independently parseable: failure в state_machine_test shouldn't block selection_test
- Memory: cleanup all test items at end (DELETE WHERE id BETWEEN test_id_min AND test_id_max)

## Next steps (next session)

1. Implement `lib/pipeline_contracts.php` (~150 lines, mostly thin asserts wrapping EPV2_Queue accessors)
2. Implement `lib/mock_worker.php` (override worker URL to local mock, ~80 lines)
3. Write fixture 01 (politik priority news) end-to-end as proof-of-concept
4. Iterate on remaining 24 fixtures with same pattern
5. Wire into CI / pre-commit hook eventually

Estimated effort: 6h initial scaffolding + 2-3h fixture creation per category cluster.
