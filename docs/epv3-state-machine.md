# EPV3 State Machine

## Queue States

- `queued`
- `processing`
- `retry`
- `ready_publish`
- `published`
- `failed_hard`

## Stages

- `ingested`
- `initial_filtered`
- `context_analyzed`
- `dossier_built`
- `de_master_ready`
- `media_ready`
- `uk_ready`
- `en_ready`
- `publish_ready`
- `published`

## Allowed Transitions

- `queued + ingested -> processing + ingested`
- `processing + ingested -> queued + initial_filtered`
- `processing + initial_filtered -> queued + context_analyzed`
- `processing + context_analyzed -> queued + dossier_built`
- `processing + dossier_built -> queued + de_master_ready`
- `processing + de_master_ready -> queued + media_ready`
- `processing + media_ready -> queued + uk_ready`
- `processing + uk_ready -> queued + en_ready`
- `processing + en_ready -> ready_publish + publish_ready`
- `ready_publish + publish_ready -> published + published`

## Retry Policy

- stage failure -> `retry` with same stage;
- same item resumes from failed stage, not from the beginning;
- retry window and retry reason must be explicit;
- hard failure is allowed only when:
  - source is unrecoverably invalid;
  - factual conflict cannot be resolved;
  - infrastructure is broken for too long.

## Manual Review Policy

Для `EPV3` ручной review не является стандартным queue state.
Сначала система обязана попытаться:

- enrichment;
- media recovery;
- translation retry;
- publish-finish repair.

Ручная работа допустима только как операторский override, а не как штатный автоматический выход.
