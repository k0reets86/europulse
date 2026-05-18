"""
EuroPulse AutoPilot v2.1 — Story Card builder.

Single upfront AI pass that turns a raw incoming news item into a
structured "story card": semantic category, geography, named entities,
key facts, suggested tags, search queries, media hints, SEO hints,
publishability estimate.

Downstream stages (categorizer, media resolver, rewriter, SEO,
tagger) consume the card so they all work from the same understanding
instead of each running its own keyword heuristic.

Single AI call, JSON-mode output, gpt-5-mini primary with deepseek-chat
fallback. Designed to be cheap and fast: ~1.5–2 KB prompt, ≤900-token
response.
"""
from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass, field, asdict
from typing import Any

from openai import AsyncOpenAI

from .openai_compat import (
    completion_cached_tokens,
    completion_text,
    completion_total_tokens,
    reasoning_extra_body,
)
from .provider_health import (
    provider_available,
    provider_unavailable_reason,
    register_provider_failure,
    register_provider_success,
)

logger = logging.getLogger(__name__)


# Canonical EuroPulse category slugs the rewriter / WP plugin understand.
CANONICAL_CATEGORIES = (
    "politik",
    "welt",
    "ukraine",
    "wirtschaft",
    "deutschland",
    "leben-in-deutschland",
    "bayern",
    "muenchen",
    "europa",
    "kultur",
    "sport",
    "community",
)


@dataclass
class StoryCardCategory:
    primary: str = ""
    confidence: float = 0.0
    rationale: str = ""
    # Editorial-calibration cross-tag: max one extra category, only when both
    # rubrics are equally central. Sub-rubrics (Bayern, München, Auto, IT,
    # Technologie, Veranstaltungen et al.) inherit from parent automatically
    # and never count as a secondary slot. Empty string when no second tag.
    secondary: str = ""
    secondary_confidence: float = 0.0


@dataclass
class StoryCardGeography:
    primary_country: str = ""
    primary_region: str = ""
    is_international: bool = False


@dataclass
class StoryCardSeo:
    primary_keyword: str = ""
    secondary_keywords: list[str] = field(default_factory=list)
    title_pattern_hint: str = ""


@dataclass
class StoryCardRewrite:
    tone: str = "neutral_factual"
    structure: str = "lead_facts_context"
    length_profile: str = "standard"
    audience_focus: str = ""


@dataclass
class StoryCard:
    """Structured semantic snapshot built once, consumed by every later stage."""
    v: int = 1
    kind: str = "news"
    language: str = ""
    category: StoryCardCategory = field(default_factory=StoryCardCategory)
    geography: StoryCardGeography = field(default_factory=StoryCardGeography)
    entities_people: list[dict[str, str]] = field(default_factory=list)
    entities_organizations: list[dict[str, str]] = field(default_factory=list)
    entities_places: list[str] = field(default_factory=list)
    key_facts: list[str] = field(default_factory=list)
    topics: list[str] = field(default_factory=list)
    tags: list[str] = field(default_factory=list)
    search_queries: list[str] = field(default_factory=list)
    media_search_terms: list[str] = field(default_factory=list)
    media_required: str = "source_first"
    seo: StoryCardSeo = field(default_factory=StoryCardSeo)
    rewrite: StoryCardRewrite = field(default_factory=StoryCardRewrite)
    publishable_estimate: str = "medium"
    publishable_reason: str = ""
    # Editorial-calibration verdict from `docs/editorial-calibration.md`:
    #   match            — fits the rubric's «точно берём» list
    #   borderline       — falls into «условно»; importance score decides
    #   reject_low_value — falls into «точно НЕ берём»; route straight to rejected
    editorial_match: str = "match"
    editorial_reason: str = ""
    provider: str = ""
    model: str = ""
    tokens: int = 0
    cached_tokens: int = 0
    success: bool = False
    error: str = ""

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


_SYSTEM_PROMPT = (
    "You are the upfront semantic analyzer for EuroPulse, a multilingual news site "
    "(German master, Ukrainian and English variants) for the Ukrainian diaspora and "
    "broad German-speaking audience.\n\n"
    "Your job: read one raw news item (headline, optional excerpt, optional body, source URL) "
    "and emit ONE compact JSON 'story card' describing what the story is, where it happens, "
    "what category it belongs to, and what hints downstream stages need (rewrite, tag, media, SEO).\n\n"
    "Hard rules:\n"
    "1. Output strict JSON matching the schema below — no prose, no markdown, no comments.\n"
    "2. Use one of these category slugs only: " + ", ".join(CANONICAL_CATEGORIES) + ".\n"
    "3. Be honest about confidence: 0.9+ only if the headline + body unambiguously fix the category.\n"
    "4. media_required = 'source_first' if the source likely has a relevant photo (real news outlet),\n"
    "   'named_entity' for stories about a specific person/place/object that has Wikimedia coverage,\n"
    "   'stock_ok' for generic explainers, 'generated' for breaking-without-photo cases.\n"
    "5. publishable_estimate = 'reject' for promo/spam/PR/livestream/listicle/subscription pages.\n"
    "6. tags are short (1-3 words), capitalized German nouns or proper names, max 8 items.\n"
    "7. media_search_terms are concrete visual hooks (named persons, named places, named objects),\n"
    "   not abstract topics. 1-3 items.\n"
    "8. key_facts: 3-5 atomic factual statements drawn ONLY from the source — no invention.\n"
    "9. Empty strings/lists are acceptable when the source genuinely does not say.\n\n"
    "EDITORIAL CALIBRATION (per docs/editorial-calibration.md):\n"
    "Set `editorial_match` to one of:\n"
    "  - 'match'            — fits the chosen rubric's «точно берём» list\n"
    "  - 'borderline'       — falls into «условно»; importance score will decide\n"
    "  - 'reject_low_value' — falls into «точно НЕ берём» (see per-rubric stop-list below)\n"
    "Always set `editorial_reason` (short) so the operator can audit.\n\n"
    "Per-rubric stop-lists — set editorial_match='reject_low_value' when story matches:\n"
    "  politik          — внутрипартийные склоки без существа; мэрские выборы малых городов; твиттер-реакции; расписания без сути; спекуляции без источников; пресс-релизы лоббистов; процедурные движения парламента; второстепенные функционеры.\n"
    "  ukraine          — непроверенные telegram-слухи; односторонние сводки потерь без верификации; спекулятивные мирные сценарии без источников; рада-склоки без существа; российские «мы поразили X» как самостоятельная новость; непроверенные соцсетевые материалы.\n"
    "  deutschland      — мелкая криминальная хроника; рутинные пробки без значимого нарушения движения; обычный прогноз погоды без аномалий; мелкие сплетни знаменитостей; открытия мелких бизнесов; рекламные пресс-релизы; кадровые движения локальных Stadtverwaltung без значимости; спортивные результаты низших лиг.\n"
    "  wirtschaft       — крипто/NFT/Web3 хайп; «топ-5 акций» инвестсоветы; квартальные отчёты small-cap без сюрпризов; маркетинговые пресс-релизы; trader sentiment; стартап-PR; влогеры/блогеры с экономическими тейками без аналитической базы.\n"
    "  (auto sub)       — тест-драйвы; тюнинг/мотоспорт ниже Formel-1; коллекционные малотиражные модели; реклама дилеров; мелкие сюжеты про соперничество брендов без рыночных последствий.\n"
    "  (it sub)         — крипто-хайп; багфиксы в обскурных инструментах; мелкие обновления приложений; «10 советов как ускорить» формат; тест-обзоры гаджетов; влогеры и реакции YouTubers на новости вместо исходных событий.\n"
    "  (technologie sub)— «учёные открыли X» без peer-review; поп-наука clickbait; спекулятивные «технологии будущего»; маркетинговые анонсы стартапов без следа в науке.\n"
    "  welt             — локальные новости стран без международного эха; личная жизнь иностранных политиков; монархические сплетни (UK royals — только реально крупное); бульварные форматы; истории про животных; малозначимые культурные события без международного контекста; спортивные результаты иностранных лиг; криминальная сводка без системного значения; локальные природные явления вне катастроф.\n"
    "  leben-in-deutschland — generic «10 советов» без news-повода; туристический контент; рутинные бюрократические объяснения без news-привязки; личные истории без системного измерения; generic «опыт переезда» материалы без news-привязки; обзоры сервисов; реклама услуг под видом полезной информации.\n"
    "  sport            — низшие дивизионы (Bundesliga 3, Regionalliga, бельгийская/нидерландская/австрийская/швейцарская лиги — кроме случаев когда там украинский игрок крупного уровня); гандбол; американский футбол кроме Super Bowl; бейсбол/MLB; регби; крикет; esports; молодёжные U-17/U-19/U-21 без landmark; ставки/коэффициенты; сплетни про спортсменов; релизы экипировки/форм/спонсорских контрактов; местные турниры низкого уровня; контент-маркетинг типа «топ-10 голов сезона».\n"
    "  kultur           — сплетни о знаменитостях; «что звёзды носили»; личная жизнь артистов без культурной релевантности; реклама ресторанов и lifestyle-обзоры; рутинные обзоры концертов/спектаклей; анонсы выходов альбомов без контекста; рекомендации «что посмотреть/что послушать»; листиклы «топ-10 книг для лета»; российская государственная культурная программа поданная как нейтральное событие.\n"
    "  meinung          — инфлюэнсеры/блогеры; анонимные op-ed; псевдо-научные мнения; теории заговора; пропагандистские «альтернативные взгляды» (включая AfD/Wagenknecht позиции); ранты без аргумента; личные нападки в обход аргумента; религиозная/мистическая публицистика без светского угла; спекулятивная футурология без анализа; промо-эссе под видом мнения.\n"
    "  community        — личные приглашения; коммерческая реклама под видом community; pro-Russian события (включая «pro-peace»/«диалог»/«понять Россию» камуфляж); Russian Orthodox Moscow Patriarchate мероприятия; малые camera-кружки; платные тренинги/коучи; MLM; «русский язык как нейтральный мостик культуры» программы; lifestyle-мероприятия частного характера без social mission; немецкие политические партии рекламирующие свои мероприятия.\n\n"
    "Cross-tag rule (category.secondary):\n"
    "  - Set ONE primary category. Add `secondary` ONLY when both rubrics are equally central\n"
    "    (e.g. charity concert in Berlin for Ukraine = community + ukraine; political rally\n"
    "    of Ukrainians at Bundestag = politik + ukraine).\n"
    "  - Sub-rubrics (bayern, muenchen, auto, it, technologie) inherit from parent automatically;\n"
    "    they NEVER occupy the secondary slot. If story is München-local, primary stays as the\n"
    "    parent topic (e.g. community), and the München aspect is captured in geography.\n"
    "  - Never assign a secondary if you can plausibly say «primarily X, Y is incidental».\n"
    "  - Leave secondary='' when not needed.\n\n"
    "Editorial position on Russia/Ukraine (applies in politik, ukraine, welt and any rubric where the topic touches):\n"
    "  - The war is «российская агрессивная война против Украины». Never «conflict», «crisis», «special operation».\n"
    "  - Crimea + Donbass are temporarily occupied Ukrainian territory. Never «contested», never «Russian» in our framing.\n"
    "  - Russian state media = propaganda outlets. Don't treat them as neutral journalism.\n"
    "  - Don't replicate Russian narratives as facts. If quoting, mark as Russian source explicitly.\n"
    "  - Selenskyj is the President of Ukraine. Not «comedian», not «former actor».\n"
    "  - Tone: measured. Don't pile emotional adjectives on every sentence. The framing is firm; the prose is professional.\n"
)


_USER_TEMPLATE = """Source URL: {url}
Source publication (if known): {source_name}
Source language hint: {language}
Pre-existing category bias hint (may be wrong): {bias}

Original headline: {title}

Original excerpt:
{excerpt}

Original body (may be truncated):
{body}

Return JSON of the form:
{{
  "v": 1,
  "kind": "news|live_ticker|analysis|opinion|feature|community_event|service_announcement",
  "language": "de|uk|en|...",
  "category": {{
    "primary": "<slug>",
    "confidence": 0.0-1.0,
    "rationale": "short",
    "secondary": "<slug or empty>",
    "secondary_confidence": 0.0-1.0
  }},
  "geography": {{ "primary_country": "DE|UA|...", "primary_region": "", "is_international": bool }},
  "entities": {{
    "people":         [{{"name":"...","role":"..."}}, ...],
    "organizations":  [{{"name":"...","kind":"company|govt|ngo|party|club|other"}}, ...],
    "places":         ["..."]
  }},
  "key_facts": ["fact 1", ...],
  "topics": ["short topic", ...],
  "tags": ["Tag1", "Tag2", ...],
  "search_queries": ["query for supporting source", ...],
  "media_search_terms": ["concrete visual hook", ...],
  "media_required": "source_first|named_entity|stock_ok|generated",
  "seo": {{
    "primary_keyword": "...",
    "secondary_keywords": ["...", "..."],
    "title_pattern_hint": "..."
  }},
  "rewrite": {{
    "tone": "neutral_factual|analytical|reportage|live_summary",
    "structure": "lead_facts_context|narrative|analysis|live_summary",
    "length_profile": "brief|standard|long",
    "audience_focus": "..."
  }},
  "publishable_estimate": "high|medium|low|reject",
  "publishable_reason": "short why",
  "editorial_match": "match|borderline|reject_low_value",
  "editorial_reason": "short why (cite the matched rule)"
}}
"""


def _trim(text: str, limit: int) -> str:
    if not text:
        return ""
    text = re.sub(r"\s+", " ", text).strip()
    if len(text) <= limit:
        return text
    cut = text[:limit]
    space = cut.rfind(" ")
    if space > 0:
        cut = cut[:space]
    return cut.rstrip()


def _build_prompt(
    *,
    title: str,
    excerpt: str,
    body: str,
    url: str,
    source_name: str,
    language: str,
    bias: str,
) -> list[dict[str, str]]:
    return [
        {"role": "system", "content": _SYSTEM_PROMPT},
        {
            "role": "user",
            "content": _USER_TEMPLATE.format(
                url=url or "",
                source_name=source_name or "",
                language=language or "",
                bias=bias or "",
                title=_trim(title, 280),
                excerpt=_trim(excerpt, 700),
                body=_trim(body, 6000),
            ),
        },
    ]


async def _call_openai(
    *,
    api_key: str,
    model: str,
    messages: list[dict[str, str]],
    is_reasoning: bool,
) -> tuple[str, int, int]:
    """Return (text, total_tokens, cached_tokens)."""
    client = AsyncOpenAI(api_key=api_key, timeout=45)
    create_kwargs: dict[str, Any] = {
        "model": model,
        "messages": messages,
        "response_format": {"type": "json_object"},
    }
    if is_reasoning:
        # gpt-5 / o-family models reject `temperature` and use
        # `max_completion_tokens` instead of `max_tokens`. Use the helper
        # so its `reasoning_effort=minimal` lift propagates as well.
        create_kwargs["extra_body"] = reasoning_extra_body(
            model=model,
            max_completion_tokens=1200,
        )
    else:
        create_kwargs["temperature"] = 0.2
        create_kwargs["max_tokens"] = 1200
    response = await client.chat.completions.create(**create_kwargs)
    return (
        completion_text(response),
        completion_total_tokens(response),
        completion_cached_tokens(response),
    )


async def _call_deepseek(
    *,
    api_key: str,
    messages: list[dict[str, str]],
) -> tuple[str, int, int]:
    """Return (text, total_tokens, cached_tokens). DeepSeek не отдаёт cached."""
    client = AsyncOpenAI(api_key=api_key, base_url="https://api.deepseek.com", timeout=45)
    response = await client.chat.completions.create(
        model="deepseek-chat",
        messages=messages,
        response_format={"type": "json_object"},
        temperature=0.2,
        max_tokens=1200,
    )
    return (
        completion_text(response),
        completion_total_tokens(response),
        0,
    )


def _coerce_card(raw: dict[str, Any]) -> StoryCard:
    """Defensive coercion: AI may return slightly off-shape JSON; fix gently."""
    card = StoryCard()
    card.v = int(raw.get("v") or 1)
    card.kind = str(raw.get("kind") or "news").strip().lower() or "news"
    card.language = str(raw.get("language") or "").strip().lower()

    cat = raw.get("category") or {}
    if isinstance(cat, dict):
        primary = str(cat.get("primary") or "").strip().lower()
        if primary not in CANONICAL_CATEGORIES:
            primary = ""
        card.category.primary = primary
        try:
            card.category.confidence = max(0.0, min(1.0, float(cat.get("confidence") or 0.0)))
        except (TypeError, ValueError):
            card.category.confidence = 0.0
        card.category.rationale = _trim(str(cat.get("rationale") or ""), 200)
        # Editorial calibration cross-tag (max one secondary, never sub-rubric)
        secondary = str(cat.get("secondary") or "").strip().lower()
        if secondary in CANONICAL_CATEGORIES and secondary != primary:
            # Reject sub-rubric values in the secondary slot — those inherit
            # from parent automatically and must not occupy the cross-tag.
            sub_rubrics = {"bayern", "muenchen", "auto", "it", "technologie"}
            if secondary not in sub_rubrics:
                card.category.secondary = secondary
                try:
                    card.category.secondary_confidence = max(
                        0.0, min(1.0, float(cat.get("secondary_confidence") or 0.0))
                    )
                except (TypeError, ValueError):
                    card.category.secondary_confidence = 0.0

    geo = raw.get("geography") or {}
    if isinstance(geo, dict):
        card.geography.primary_country = str(geo.get("primary_country") or "").strip().upper()[:5]
        card.geography.primary_region = _trim(str(geo.get("primary_region") or ""), 80)
        card.geography.is_international = bool(geo.get("is_international"))

    ents = raw.get("entities") or {}
    if isinstance(ents, dict):
        people = ents.get("people") or []
        if isinstance(people, list):
            card.entities_people = [
                {"name": _trim(str(p.get("name") or ""), 80), "role": _trim(str(p.get("role") or ""), 80)}
                for p in people[:8]
                if isinstance(p, dict) and p.get("name")
            ]
        orgs = ents.get("organizations") or []
        if isinstance(orgs, list):
            card.entities_organizations = [
                {"name": _trim(str(o.get("name") or ""), 80), "kind": _trim(str(o.get("kind") or ""), 30)}
                for o in orgs[:6]
                if isinstance(o, dict) and o.get("name")
            ]
        places = ents.get("places") or []
        if isinstance(places, list):
            card.entities_places = [_trim(str(p), 80) for p in places[:8] if str(p).strip()]

    def _str_list(raw_list: Any, max_items: int, max_len: int) -> list[str]:
        if not isinstance(raw_list, list):
            return []
        return [_trim(str(item), max_len) for item in raw_list[:max_items] if str(item).strip()]

    card.key_facts = _str_list(raw.get("key_facts"), 6, 240)
    card.topics = _str_list(raw.get("topics"), 8, 60)
    card.tags = _str_list(raw.get("tags"), 8, 40)
    card.search_queries = _str_list(raw.get("search_queries"), 4, 120)
    card.media_search_terms = _str_list(raw.get("media_search_terms"), 4, 80)

    media_req = str(raw.get("media_required") or "source_first").strip().lower()
    if media_req not in ("source_first", "named_entity", "stock_ok", "generated"):
        media_req = "source_first"
    card.media_required = media_req

    seo = raw.get("seo") or {}
    if isinstance(seo, dict):
        card.seo.primary_keyword = _trim(str(seo.get("primary_keyword") or ""), 80)
        card.seo.secondary_keywords = _str_list(seo.get("secondary_keywords"), 5, 60)
        card.seo.title_pattern_hint = _trim(str(seo.get("title_pattern_hint") or ""), 120)

    rw = raw.get("rewrite") or {}
    if isinstance(rw, dict):
        tone = str(rw.get("tone") or "neutral_factual").strip().lower()
        if tone not in ("neutral_factual", "analytical", "reportage", "live_summary"):
            tone = "neutral_factual"
        card.rewrite.tone = tone
        structure = str(rw.get("structure") or "lead_facts_context").strip().lower()
        if structure not in ("lead_facts_context", "narrative", "analysis", "live_summary"):
            structure = "lead_facts_context"
        card.rewrite.structure = structure
        length = str(rw.get("length_profile") or "standard").strip().lower()
        if length not in ("brief", "standard", "long"):
            length = "standard"
        card.rewrite.length_profile = length
        card.rewrite.audience_focus = _trim(str(rw.get("audience_focus") or ""), 120)

    estimate = str(raw.get("publishable_estimate") or "medium").strip().lower()
    if estimate not in ("high", "medium", "low", "reject"):
        estimate = "medium"
    card.publishable_estimate = estimate
    card.publishable_reason = _trim(str(raw.get("publishable_reason") or ""), 200)

    editorial = str(raw.get("editorial_match") or "match").strip().lower()
    if editorial not in ("match", "borderline", "reject_low_value"):
        editorial = "match"
    card.editorial_match = editorial
    card.editorial_reason = _trim(str(raw.get("editorial_reason") or ""), 200)
    return card


async def build_story_card(
    *,
    title: str,
    excerpt: str,
    body: str,
    url: str,
    source_name: str = "",
    language_hint: str = "",
    category_bias: str = "",
    openai_api_key: str = "",
    deepseek_api_key: str = "",
    provider_order: tuple[str, ...] = ("openai", "deepseek"),
    openai_model: str = "gpt-4o-mini",
) -> StoryCard:
    """Run one upfront AI call to build a story card. Tries providers in order.

    Returns a StoryCard with `success=True` on first parseable response.
    On total failure returns a StoryCard with `success=False` and `error=...`;
    callers should fall back to heuristic categorizer / tagger / media rules.
    """
    messages = _build_prompt(
        title=title,
        excerpt=excerpt,
        body=body,
        url=url,
        source_name=source_name,
        language=language_hint,
        bias=category_bias,
    )
    last_error = ""
    for provider in provider_order:
        if not provider_available(provider):
            last_error = f"{provider}_cooldown: {provider_unavailable_reason(provider)}"
            continue
        try:
            if provider == "openai":
                if not openai_api_key:
                    continue
                is_reasoning = (
                    openai_model.startswith("gpt-5")
                    or openai_model.startswith("o1")
                    or openai_model.startswith("o3")
                    or openai_model.startswith("o4")
                )
                raw_text, used_tokens, used_cached = await _call_openai(
                    api_key=openai_api_key,
                    model=openai_model,
                    messages=messages,
                    is_reasoning=is_reasoning,
                )
                used_provider = "openai"
                used_model = openai_model
            elif provider == "deepseek":
                if not deepseek_api_key:
                    continue
                raw_text, used_tokens, used_cached = await _call_deepseek(
                    api_key=deepseek_api_key,
                    messages=messages,
                )
                used_provider = "deepseek"
                used_model = "deepseek-chat"
            else:
                continue
            try:
                parsed = json.loads(raw_text or "{}")
            except json.JSONDecodeError as exc:
                last_error = f"json_decode({provider}): {exc}"
                register_provider_failure(provider, exc)
                logger.warning("Story card JSON parse failed via %s: %s", provider, exc)
                continue
            if not isinstance(parsed, dict):
                last_error = f"non_object({provider})"
                register_provider_failure(provider, last_error)
                continue
            card = _coerce_card(parsed)
            card.success = True
            card.provider = used_provider
            card.model = used_model
            card.tokens = used_tokens
            card.cached_tokens = used_cached
            register_provider_success(provider)
            return card
        except Exception as exc:  # noqa: BLE001
            last_error = f"{provider}_error: {exc}"
            register_provider_failure(provider, exc)
            logger.warning("Story card via %s failed: %s", provider, exc)
            continue

    fallback = StoryCard()
    fallback.success = False
    fallback.error = last_error or "no_provider_available"
    return fallback
