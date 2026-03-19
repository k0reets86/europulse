# Gemini Draft/Test Profile for EuroPulse AutoPilot v2

Дата: 2026-03-15

## Что проверено по официальной документации Google

- Бесплатный тариф Gemini API не ограничивается только `Lite`-моделями.
- В актуальной таблице rate limits у Google есть free tier для нескольких моделей, включая:
  - `Gemini 2.5 Pro`
  - `Gemini 2.5 Flash`
  - `Gemini 2.5 Flash-Lite`
  - `Gemini 2.0 Flash`
  - `Gemini 2.0 Flash-Lite`
- Для `Gemini 2.5 Flash` и `Gemini 2.5 Flash-Lite` в документации отмечены поддержка:
  - `Grounding with Google Search`
  - `URL context`

Официальные источники:

- https://ai.google.dev/gemini-api/docs/pricing
- https://ai.google.dev/gemini-api/docs/models
- https://ai.google.dev/gemini-api/docs/google-search
- https://ai.google.dev/gemini-api/docs/url-context

## Какой профиль выбран для EuroPulse

Для черновиков и тестов по умолчанию выбран:

- provider: `gemini`
- model: `gemini-2.5-flash-lite`
- post status: `draft`
- rewrite style: `strict`
- Google Search grounding: `enabled`
- URL context: `enabled`
- source URL in prompt: `enabled`
- citations required in visible article text: `disabled`

## Почему выбран именно этот профиль

- `2.5 Flash-Lite` быстрее и дешевле, чем `2.5 Pro`, и лучше подходит для массовой черновой генерации.
- В отличие от старого `2.0 Flash-Lite`, модельный ряд `2.5` лучше подходит для web-assisted draft generation.
- Для production-публикации можно позже переключиться на:
  - `gemini-2.5-flash`
  - или `gemini-2.5-pro`
  если потребуется более сильный reasoning и более сложный quality pass.

## Практическое правило для плагина

- Черновики и полуавтоматический review: `gemini-2.5-flash-lite`
- Более дорогой финальный quality pass: опционально `gemini-2.5-flash` или `gemini-2.5-pro`
- Если Gemini недоступен:
  - fallback provider: `deepseek`
  - fallback model: `deepseek-chat`

## Ограничение текущего стенда

На сервере сейчас не задан рабочий `Gemini API key`, поэтому:

- профиль и transport уже настроены,
- но живой запрос к Google из плагина пока не был выполнен.

Как только ключ будет добавлен в `Настройки`, можно делать живой end-to-end тест:

1. collect
2. process
3. review regenerate
4. draft publish
