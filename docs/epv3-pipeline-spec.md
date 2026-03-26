# EPV3 Pipeline Spec

## Target Flow

1. `ingested`
2. `initial_filtered`
3. `context_analyzed`
4. `dossier_built`
5. `de_master_ready`
6. `media_ready`
7. `uk_ready`
8. `en_ready`
9. `publish_ready`
10. `published`

## Processing Principles

- один orchestrator;
- один queue store;
- один item в тяжёлой обработке одновременно по умолчанию;
- короткие stage transitions;
- следующий stage не стартует, пока предыдущий не подтверждён;
- retries должны быть stage-specific.

## Stage Rules

### 1. ingested

- сохранить исходные поля;
- определить исходный язык;
- записать первичный score/signal только как rough intake estimate.

### 2. initial_filtered

- отсеять мусор, шум, служебные страницы, нерелевантные листинги, soft-block/consent pages;
- не принимать решение о ценности материала только по headline.

### 3. context_analyzed

- анализировать body/content;
- определить:
  - entities;
  - тема;
  - подтема;
  - практическая ценность;
  - urgency;
  - категорию;
  - search terms для enrichment.

### 4. dossier_built

- собрать минимум 2 релевантных источника, если исходник сам по себе не даёт достаточной фактуры;
- использовать контекстный поиск, а не headline-only search;
- собрать source dossier и media candidates.

### 5. de_master_ready

- построить немецкий мастер;
- сделать editorial rewrite;
- сохранить структурированный payload;
- убедиться, что именно DE-версия является главной опорной версией.

### 6. media_ready

- сначала использовать релевантное source media;
- если remote media нестабильно, пытаться импортировать локально;
- если source media отсутствует или непригодно, искать по dossier/context;
- сохранить attribution/source link.

### 7. uk_ready

- перевести из финального `DE master`;
- не стартовать раньше готового `DE + media`.

### 8. en_ready

- перевести из финального `DE master`;
- те же ограничения, что для `uk_ready`.

### 9. publish_ready

- material package проверен как publish-grade;
- ставится `publish_not_before`;
- публикация разрешена только после полного дополнительного цикла.

### 10. published

- создан WP post;
- сохранены links/meta/media attribution/state.

## Non-Negotiable Technical Constraints

- read-only selector: выбор следующего item не должен писать в БД побочные данные;
- никаких тяжёлых side effects в `init/admin_init`;
- никаких длинных HTTP-запусков pipeline;
- retries не должны пересобирать весь bundle, если сломан только один stage.
