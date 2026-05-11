# Dedicated `card_lead` field — design 2026-05-09

## Проблема

Карточки на главной + рубричных страницах берут текст из `post_excerpt` (=
AI-сгенерированный `lead_paragraph` статьи) и ОБРЕЗАЮТ его до визуального
бюджета (3 строки × ~50ch для DE/EN, ~45ch для UK). Любая мid-sentence
обрезка ломает лид-магнит:

- «Олександр Цверев виграв свій перший матч... у Римі **проти.**» (висящий предлог)
- «...Keir Starmer, der Vorsitzende **der Labour Party.**» (без предиката)
- «EU-Außenpolitikchefin Kaja Kallas besuchte am **8.**» (8 Mai сплитнулся как два предложения)
- «...gegen seinen Landsmann **Daniel.**» (имя обрезано)

Триммер можно бесконечно крутить (regex для висящих предлогов / артиклей /
аббревиатур), но **источник всё равно остаётся "первым предложением статьи",
которое не оптимизировано под карточку**. Контракт неверный.

## Решение

Отдельное AI-генерируемое поле `card_lead` — короткое (~110-130 chars в
зависимости от языка), законченное по смыслу, цепляющее предложение под
визуальный бюджет 3-line карточки. Никакой server-side обрезки — что AI
сгенерил, то и показываем.

## Затронутые слои

| Слой | Изменение |
| --- | --- |
| `worker-v21/src/.../contracts.py` | + `LanguagePackage.card_lead: str = ""` |
| `worker-v21/src/.../rewriter.py` | DE master prompt: добавить инструкцию + JSON-поле `card_lead` (target 110-130 chars, single sentence, hook style); fallback = `lead_de` если пусто |
| `worker-v21/src/.../translation.py` (или где переводы) | UK target 95-115 chars (cyrillic wider), EN target 110-130 chars; перевод через тот же путь что и `excerpt`/`title` |
| `worker-v21/src/.../pipeline.py` | `build_normalized_payload` добавляет `"card_lead"` в каждый из `de/uk/en` блоков |
| `wp-plugins/.../publish/class-epv2-publisher.php` | при записи поста — `update_post_meta($id, '_europulse_card_lead', $lang_payload['card_lead'])` |
| `mu-plugins/europulse-foundation/includes/core.php` | `europulse_context_excerpt()`: в начале `$lead = get_post_meta($post_id, '_europulse_card_lead', true)`; если непусто и ≥ 40 chars — вернуть `wp_strip_all_tags($lead)` без обрезки/finalize. Иначе fallback в существующую логику. |

## Контракт качества для AI

Промпт для DE master (добавляется в существующий JSON output schema):

```
card_lead: ОДНО законченное предложение, 110-130 знаков, для карточки на
главной странице. Это лид-магнит — он должен зацепить и заставить кликнуть.
Не повторять title. Не использовать аббревиатуры (никаких «8. Mai», «z. B.»).
Не обрывать на предлоге / союзе. Должно быть полностью самостоятельным —
читатель должен понять суть истории, не открывая статью.
```

Перевод для UK/EN: при переводе `excerpt`/`title` — отдельная инструкция для
`card_lead` с целевой длиной языка.

## Backward compat

- Старые посты (без `_europulse_card_lead`) — рендерятся через
  существующий `europulse_context_excerpt` пайплайн (с улучшенным
  finalize_sentence из этой же сессии).
- Новые посты получают `card_lead` от worker'а — рендерятся напрямую.
- Никаких миграций / backfill'а — старые посты доскроллятся за неделю.

## Failure modes & mitigations

- **AI игнорирует длину** — добавить post-validation: если `card_lead`
  пусто, > 200 chars или содержит невалидные символы (троеточие посреди,
  «...» в конце, незакрытые скобки) — fallback на старую логику.
- **AI генерирует тот же текст что и lead_paragraph** — добавить в промпт
  «card_lead должен ОТЛИЧАТЬСЯ от lead тем что короче и компактнее».
- **AI cost** — +1 поле в DE master + 1 поле в каждом переводе. Token cost
  ~+50-80 per item, +~5% к стоимости. Приемлемо для качественного
  улучшения главной.

## Quality metrics

Через неделю проверить:
- % посто́в с непустым `card_lead`
- средняя длина `card_lead` по языкам
- % случаев когда mu-plugin использует `card_lead` (vs fallback)
- ручной audit 20 случайных карточек: законченные ли предложения

## Roadmap

1. Contract + worker prompt (DE+UK+EN + post-validation).
2. Pipeline payload + PHP publisher persistence.
3. Mu-plugin reader + fallback wiring.
4. Deploy + verify on next batch (через час будут новые посты).
5. Cleanup триммера: можно сильно упростить regex-эвристики если все
   карточки идут через `card_lead`. Оставить fallback, но не вылизывать.

## Открытые вопросы (на решение оператором)

1. **Длина для UK** — 95-115 chars? Можно мерить эмпирически: открыть
   главную, замерить визуально fits в 3 строки. Сейчас limit_uk_latest=75
   слишком короткий, реально влезает 110-120.
2. **Один card_lead или два** (короткий для section-list 2-line + длинный
   для 3-line карточек)? Один проще, two — качественнее. Предлагаю
   начать с одного длинного, CSS line-clamp:2 в section-list корректно
   обрежет визуально.
3. **Нужна ли отдельная AI-стадия** или это часть DE master? Часть DE
   master проще; отдельная стадия = +cost, но позволяет лучше тюнить
   промпт. Предлагаю начать с части DE master.
