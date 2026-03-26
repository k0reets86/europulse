# EPV3 Mandatory Knowledge Pack

## Purpose

Этот файл обязателен как входная спецификация для `EPV3`.
Задача: сохранить накопленные знания из `EPV2`, не перетаскивая в `EPV3` legacy-хаос.

`EPV2` остаётся reference/fallback.
`EPV3` должен собираться по этому knowledge pack, а не по памяти.

## Mandatory Rules To Preserve

### 1. Editorial Goal

- Итоговый pipeline должен публиковать только качественные материалы.
- Слабый материал нельзя просто отсеивать, если его можно усилить через другие источники.
- Ручной review должен быть редким исключением, а не штатным путём.

### 2. DE-First Principle

- Любой входной язык сначала нормализуется в немецкий мастер (`DE master`).
- Если исходник не немецкий, сначала перевод/нормализация в немецкий.
- Весь тяжёлый editorial/enrichment/media/SEO контур делается только на `DE master`.
- `UK/EN` создаются только после того, как `DE` доведён до publish-grade.

### 3. Analysis Must Use Body, Not Only Title

- Первичный intake может смотреть на title/headline.
- Но реальное понимание темы, полезности, категории и приоритетности должно строиться по body/content.
- `context_analysis` должен опираться на:
  - body текста
  - source dossier
  - event context
  - entities / keywords / story context

### 4. Selection And Filtering

- Нужно отсеивать шум, мусор, служебные страницы, слабые сигналы и мусорные пресс-релизы.
- Но нельзя отбрасывать полезный материал только потому, что исходник короткий.
- Если сигнал важный, но короткий:
  - сначала enrichment
  - потом повторная оценка

### 5. Enrichment Rules

- При слабой фактуре материал должен усиливаться через 1-3 дополнительных релевантных источника.
- Поиск дописточников должен идти по сохранённому контексту темы, а не по одному headline.
- Нужно сохранять context memory между retry/stages до `ready_publish`.

### 6. Media Rules

- Media должно быть релевантным теме текста.
- Если картинка из первоисточника релевантна, её нужно использовать приоритетно.
- Если remote media нестабильно:
  - пытаться импортировать/зеркалировать локально
  - сохранять атрибуцию и ссылку на первоисточник
- Если в dossier нет картинки:
  - искать по контексту темы в других публикациях
- Нельзя использовать:
  - generic stock
  - watermark/logo
  - технические assets
  - нерелевантное featured media

### 7. Media Attribution

- Нужно правильно титровать media.
- В материале должна сохраняться ссылка на первоисточник media, если она известна.
- Если media взято с первоисточника статьи, это должно быть отражено корректно.

### 8. Citation And Linking Rules

- Если используется прямая речь, нужно указывать не только автора, но и площадку/контекст.
- Ссылки в тексте должны вести на первоисточник по делу, а не случайно.
- Нельзя оставлять цитату без ясной атрибуции.

### 9. Style Rules

- Тексты должны быть живыми, newsroom-style, без канцелярита и пресс-релизной сухости.
- Нужен сильный короткий headline, лид из 2 предложений, естественные переходы.
- Нельзя расползаться в длинные пустые тексты.
- Стиль должен соответствовать накопленным prompt rules из `EPV2`.

### 10. SEO/Meta Rules

- До `ready_publish` должны быть заполнены:
  - SEO title
  - meta description
  - slug
  - focus keywords / equivalent key phrase data
  - featured media / google image readiness
- `100/100` должно быть реальным, а не мнимым.

### 11. Queue And Publish Rules

- Stage order должен быть строгим и подтверждаемым:
  - source received
  - analyzed
  - context saved
  - dossier built
  - DE ready
  - media ready
  - UK ready
  - EN ready
  - publish finish ready
  - ready_publish
  - published
- Следующая стадия не должна стартовать, пока не подтверждена предыдущая.
- После `ready_publish` публикация должна ждать полный дополнительный цикл.

### 12. Retry Rules

- Retry должен быть stage-specific.
- Если сломано media, нельзя пересобирать весь multilingual bundle.
- Если сломан перевод, нельзя заново rebuild-ить весь `DE`.
- Если нужен enrichment, нужно возвращаться именно к enrichment-stage.

## Required Extraction From EPV2

Перед разработкой `EPV3` обязательно вынуть из `EPV2`:

- prompts and prompt fragments
- source allow/deny logic
- selection/budget rules
- category mapping logic
- `context_analysis` rules
- story context / dossier structure
- DE/UK/EN style constraints
- media relevance rules
- media attribution rules
- quote/citation/linking rules
- publish gates and quality thresholds
- ready_publish delay logic

## Files To Build Next

- `docs/epv3-editorial-spec.md`
- `docs/epv3-pipeline-spec.md`
- `docs/epv3-state-machine.md`
- `config/epv3-prompts/`
- `config/epv3-rules/`

## Non-Negotiable Constraint

`EPV3` нельзя делать как упрощённую версию.
Нужно сохранить качество, накопленные редакционные нюансы и DE-first логику, но избавиться от legacy-архитектуры `EPV2`.
