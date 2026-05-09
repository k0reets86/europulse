"""
EuroPulse AutoPilot v2.1 — Pipeline Orchestrator

Stages:
  full_bundle  — full pipeline: semantic → enrich → rewrite DE → translate → media → SEO
  title        — regenerate title only
  lead         — regenerate lead only
  body         — regenerate body only
  media        — restart media search
  seo          — regenerate SEO fields
"""
from __future__ import annotations

import asyncio
import html
import logging
import re
from dataclasses import dataclass, field
from urllib.parse import urlparse

from .contracts import LanguagePackage, MediaCandidate, WorkerRequest, WorkerResponse
from .semantic import analyze as semantic_analyze, SemanticResult
from .rewriter import rewrite_to_german
from .translator import translate_from_german
from .media import find_media, MediaResult
from .seo import generate_seo

logger = logging.getLogger(__name__)


@dataclass
class PipelineContext:
    request: WorkerRequest
    semantic: SemanticResult = field(default_factory=SemanticResult)
    source_dossier: dict = field(default_factory=dict)
    categories: list[str] = field(default_factory=list)
    tags: list[str] = field(default_factory=list)
    featured_media: MediaResult = field(default_factory=MediaResult)
    german_master: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="de"))
    ukrainian: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="uk"))
    english: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="en"))
    warnings: list[str] = field(default_factory=list)
    blockers: list[str] = field(default_factory=list)
    ai_runtime: list[dict[str, str]] = field(default_factory=list)
    effective_length_profile: str = ""  # may differ from request.length_profile after enrichment

    @property
    def openai_key(self) -> str:
        return str(self.request.editorial_flags.get("openai_api_key", ""))

    @property
    def deepseek_key(self) -> str:
        return str(self.request.editorial_flags.get("deepseek_api_key", ""))

    @property
    def pexels_key(self) -> str:
        return str(self.request.editorial_flags.get("pexels_api_key", ""))

    @property
    def provider_order(self) -> list[tuple[str, str, str]]:
        flags = self.request.editorial_flags
        keys = {
            "openai": str(flags.get("openai_api_key", "")),
            "deepseek": str(flags.get("deepseek_api_key", "")),
        }
        models = {
            "openai": str(flags.get("ai_model", "")) or "gpt-4o-mini",
            "deepseek": str(flags.get("ai_model", "")) or "deepseek-chat",
        }
        fallback_models = {
            "openai": str(flags.get("ai_fallback_model", "")) or "gpt-4o-mini",
            "deepseek": str(flags.get("ai_fallback_model", "")) or "deepseek-chat",
        }
        order: list[tuple[str, str, str]] = []
        primary = str(flags.get("ai_provider", "openai"))
        fallback = str(flags.get("ai_fallback_provider", ""))
        if primary in keys and keys[primary]:
            order.append((primary, keys[primary], models[primary]))
        if fallback in keys and keys[fallback] and fallback != primary:
            order.append((fallback, keys[fallback], fallback_models[fallback]))
        for provider, key in keys.items():
            if key and provider not in {candidate[0] for candidate in order}:
                order.append((provider, key, "gpt-4o-mini" if provider == "openai" else "deepseek-chat"))
        return order


async def run_pipeline(request: WorkerRequest) -> WorkerResponse:
    ctx = PipelineContext(request=request)
    stage = request.stage

    if stage == "full_bundle":
        await _run_full_bundle(ctx)
    elif stage == "title":
        await _regen_title(ctx)
    elif stage == "lead":
        await _regen_lead(ctx)
    elif stage == "body":
        await _regen_body(ctx)
    elif stage == "media":
        await _regen_media(ctx)
    elif stage == "seo":
        await _regen_seo(ctx)
    else:
        await _run_full_bundle(ctx)

    if ctx.blockers:
        return _build_response(ctx, "ready_review")
    return _build_response(ctx, "ready_publish")


# ---------------------------------------------------------------------------
# Full bundle
# ---------------------------------------------------------------------------

_CONTENT_TYPE_CATEGORY: dict[str, str] = {
    "sport":     "sport",
    "kultur":    "kultur",
    "service":   "service",
    "community": "community",
}

_LENGTH_UPGRADE: dict[str, str] = {
    "brief":    "standard",
    "standard": "long",
    "long":     "long",
    "analysis": "analysis",
}


async def _run_full_bundle(ctx: PipelineContext) -> None:
    req = ctx.request
    original_text = _clean_original_text(req.original_content or req.original_excerpt or "")

    # 1. Semantic analysis
    ctx.semantic = semantic_analyze(
        title=req.original_title,
        content=original_text,
        hint_lang=req.source_language,
    )
    ctx.tags = ctx.semantic.key_phrases[:5]

    # Pull the upfront story card out of existing_payload once. We use it
    # below to seed categories (overrides the heuristic content_type ->
    # category mapping when the card is high-confidence) and tags (which
    # the worker would otherwise build from TF-IDF key phrases that look
    # noisy on translation).
    _existing_payload_init = getattr(req, "existing_payload", None) or {}
    _story_card_init: dict | None = None
    if isinstance(_existing_payload_init, dict):
        _meta_init = _existing_payload_init.get("_meta") or {}
        if isinstance(_meta_init, dict):
            sc = _meta_init.get("story_card")
            if isinstance(sc, dict) and sc:
                _story_card_init = sc

    # Group F: override category from semantic content_type when unambiguous;
    # story card category wins over both when its confidence is high enough.
    semantic_cat = _CONTENT_TYPE_CATEGORY.get(ctx.semantic.content_type, "")
    if semantic_cat:
        ctx.categories = [semantic_cat]
    else:
        ctx.categories = [req.category_proposed] if req.category_proposed else []
    if _story_card_init:
        card_cat = (_story_card_init.get("category") or {})
        primary = str(card_cat.get("primary") or "").strip()
        try:
            confidence = float(card_cat.get("confidence") or 0.0)
        except (TypeError, ValueError):
            confidence = 0.0
        if primary and confidence >= 0.6:
            ctx.categories = [primary]
        # Editorial-calibration cross-tag: append secondary if the card chose
        # one. Story Card already enforced the rules (no sub-rubric in this
        # slot, equal-importance test); we just propagate it here so the
        # publisher can wp_set_post_terms() with both categories.
        secondary = str(card_cat.get("secondary") or "").strip()
        try:
            sec_conf = float(card_cat.get("secondary_confidence") or 0.0)
        except (TypeError, ValueError):
            sec_conf = 0.0
        if (
            secondary
            and secondary != primary
            and secondary not in ctx.categories
            and sec_conf >= 0.6
        ):
            ctx.categories.append(secondary)
        # Replace TF-IDF tag stub with the curated tags from the card —
        # they are clean German nouns, capitalized, vetted by the LLM.
        card_tags = _story_card_init.get("tags") or []
        if isinstance(card_tags, list):
            cleaned = [str(t).strip() for t in card_tags if isinstance(t, (str,)) and str(t).strip()]
            if cleaned:
                ctx.tags = cleaned[:8]

    # 2. Enrich sources — mandatory for thin content (<500 words), otherwise only when semantic flags it.
    # The PHP build_payload now seeds short Google-News-stub bodies with
    # the upfront story_card key_facts + named entities + locations so a
    # 15-word headline-only feed item arrives here as a 100+ word stitched
    # brief. Lower the absolute floor to 18 words so headline-only items
    # without ANY card grounding still get flagged, but a card-stitched
    # brief survives. When the card carries 3+ key_facts we trust it as a
    # substantive editorial basis and skip the blocker entirely.
    source_word_count = len(original_text.split())
    card_facts_count = 0
    if isinstance(_story_card_init, dict):
        card_facts_count = len(_story_card_init.get("key_facts") or [])
    if source_word_count < 18 and card_facts_count < 3:
        ctx.blockers.append("Primary source too thin for autopublish")
    force_enrichment = source_word_count < 500
    supporting_urls: list[str] = []
    supporting_rich: list[dict[str, str]] = []
    if ctx.semantic.needs_enrichment or force_enrichment:
        # Phase 2.7 — primary path: structured supporting sources with
        # title + domain so the rewriter can name each one in the
        # synthesis. Plain-URL list is kept for legacy media/dossier code.
        supporting_rich = await _search_supporting_sources_rich(
            ctx.semantic.key_phrases, req.original_url, limit=5
        )
        supporting_urls = [entry["url"] for entry in supporting_rich]

    # Group E: adjust length profile based on source richness and enrichment outcome
    effective_length_profile = req.length_profile or "standard"
    ctx.effective_length_profile = effective_length_profile
    if force_enrichment:
        # Supporting URLs are not supporting facts. Until the worker actually
        # extracts article text from those URLs, thin feeds must stay compact;
        # otherwise the rewriter fills the requested length with assumptions.
        if source_word_count < 220:
            effective_length_profile = "brief"
        elif source_word_count < 500 and effective_length_profile not in {"brief", "standard"}:
            effective_length_profile = "standard"
    ctx.effective_length_profile = effective_length_profile
    ctx.source_dossier = {
        "primary": {
            "url": req.original_url,
            "title": req.original_title,
            "excerpt": req.original_excerpt,
        },
        "supporting": [{"url": url} for url in supporting_urls],
    }

    # 3. Rewrite to German.
    # The PHP processor builds an upfront semantic story card (one AI call
    # per item) and persists it in `existing_payload._meta.story_card`.
    # When present, hand it to the rewriter so it can anchor on the same
    # entities and key facts that drove categorization, instead of running
    # an independent "best guess" from the raw source text.
    existing_payload = getattr(req, "existing_payload", None) or {}
    story_card_for_rewrite: dict | None = None
    rewrite_kind: str = ""
    rewrite_dossier_block: str = ""
    if isinstance(existing_payload, dict):
        meta = existing_payload.get("_meta") or {}
        if isinstance(meta, dict):
            sc = meta.get("story_card")
            if isinstance(sc, dict) and sc:
                story_card_for_rewrite = sc
            # Phase 2.3: kind set by EPV2_Content_Kinds::detect_kind() upstream.
            ck = meta.get("content_kind")
            if isinstance(ck, str) and ck:
                rewrite_kind = ck
            # Build a compact dossier block from primary + related sources.
            # Phase 2.7: web_search-derived supporting sources from
            # _search_supporting_sources_rich are merged in below (after
            # this block) — they're the live primary enrichment now,
            # in-house siblings are demoted to echo-block role per
            # architecture audit section 2 step 5.
            dossier = meta.get("source_dossier") or {}
            related_entries: list[dict[str, str]] = []
            if isinstance(dossier, dict):
                stored_related = dossier.get("related") or []
                if isinstance(stored_related, list):
                    for entry in stored_related[:6]:
                        if isinstance(entry, dict):
                            related_entries.append({
                                "title": str(entry.get("title") or "").strip(),
                                "url": str(entry.get("url") or "").strip(),
                                "domain": str(entry.get("domain") or "").strip(),
                            })
            for entry in supporting_rich[:6]:
                related_entries.append({
                    "title": entry.get("title", ""),
                    "url": entry.get("url", ""),
                    "domain": entry.get("domain", ""),
                })
            if related_entries:
                lines = ["DOSSIER (Zusatzquellen für Synthese, jede beim Einbringen namentlich nennen):"]
                seen: set[str] = set()
                for entry in related_entries:
                    domain = entry["domain"] or entry["url"]
                    title = entry["title"]
                    key = (domain, title[:60])
                    if key in seen or not (title and (domain or entry["url"])):
                        continue
                    seen.add(key)
                    lines.append(f"  • {domain}: {title}")
                if len(lines) > 1:
                    rewrite_dossier_block = "\n".join(lines)

    # Phase 2.3: rubric_slug — prefer category_final, fall back to category_proposed.
    rewrite_rubric = (req.category_final or req.category_proposed or "").strip().lower()

    rewrite = await rewrite_to_german(
        original_title=req.original_title,
        original_content=original_text,
        source_language=ctx.semantic.detected_language,
        content_type=ctx.semantic.content_type,
        key_phrases=ctx.semantic.key_phrases,
        openai_api_key=ctx.openai_key,
        deepseek_api_key=ctx.deepseek_key,
        provider_order=ctx.provider_order,
        length_profile=effective_length_profile,
        source_url=req.original_url,
        story_card=story_card_for_rewrite,
        kind=rewrite_kind,
        rubric_slug=rewrite_rubric,
        dossier_block=rewrite_dossier_block,
    )
    if not rewrite.success:
        ctx.blockers.append(f"Rewrite failed: {rewrite.error}")
        return
    _record_ai_runtime(ctx, "rewrite_de", rewrite.provider, rewrite.model, getattr(rewrite, "tokens", 0))

    # Anti-plagiarism gate (architecture phase 3) — surface the score.
    # Failure does not block the pipeline yet; the WP-side gate uses the
    # warning to decide on regeneration within its 2-attempts budget.
    if not rewrite.uniqueness_passed:
        ctx.warnings.append(
            f"plagiarism_gate_de: uniqueness {rewrite.uniqueness_pct:.1f}% < 80%"
            + (f" ({rewrite.uniqueness_reason})" if rewrite.uniqueness_reason else "")
        )

    ctx.german_master = LanguagePackage(
        lang="de",
        title=rewrite.title_de,
        excerpt=rewrite.lead_de,
        content=rewrite.body_de,
    )

    # 4. Translate to UK + EN in parallel
    uk_task = asyncio.create_task(
        translate_from_german(
            rewrite.title_de, rewrite.lead_de, rewrite.body_de,
            target_lang="Ukrainian",
            openai_api_key=ctx.openai_key,
            deepseek_api_key=ctx.deepseek_key,
            provider_order=ctx.provider_order,
        )
    )
    en_task = asyncio.create_task(
        translate_from_german(
            rewrite.title_de, rewrite.lead_de, rewrite.body_de,
            target_lang="English",
            openai_api_key=ctx.openai_key,
            deepseek_api_key=ctx.deepseek_key,
            provider_order=ctx.provider_order,
        )
    )
    uk_result, en_result = await asyncio.gather(uk_task, en_task)

    ctx.ukrainian = LanguagePackage(
        lang="uk",
        title=uk_result.title,
        excerpt=uk_result.lead,
        content=uk_result.body,
    )
    ctx.english = LanguagePackage(
        lang="en",
        title=en_result.title,
        excerpt=en_result.lead,
        content=en_result.body,
    )

    if not uk_result.success:
        ctx.blockers.append(f"UK translation failed: {uk_result.error}")
    else:
        _record_ai_runtime(ctx, "translate_uk", uk_result.provider, uk_result.model, getattr(uk_result, "tokens", 0))
        if not uk_result.uniqueness_passed:
            ctx.warnings.append(
                f"plagiarism_gate_uk: uniqueness {uk_result.uniqueness_pct:.1f}% < 80%"
            )
    if not en_result.success:
        ctx.blockers.append(f"EN translation failed: {en_result.error}")
    else:
        _record_ai_runtime(ctx, "translate_en", en_result.provider, en_result.model, getattr(en_result, "tokens", 0))
        if not en_result.uniqueness_passed:
            ctx.warnings.append(
                f"plagiarism_gate_en: uniqueness {en_result.uniqueness_pct:.1f}% < 80%"
            )
    for lang, package in {"de": ctx.german_master, "uk": ctx.ukrainian, "en": ctx.english}.items():
        if not _language_package_complete(package):
            ctx.blockers.append(f"{lang.upper()} language package incomplete")

    # 5. Media
    media = await find_media(
        key_phrases=ctx.semantic.key_phrases,
        source_url=req.original_url,
        supporting_urls=supporting_urls,
        pexels_api_key=ctx.pexels_key,
        query_lang="de",
    )
    ctx.featured_media = media

    # 6. SEO
    seo = await generate_seo(
        title_de=rewrite.title_de,
        lead_de=rewrite.lead_de,
        body_de=rewrite.body_de,
        key_phrases=ctx.semantic.key_phrases,
        openai_api_key=ctx.openai_key,
        deepseek_api_key=ctx.deepseek_key,
        provider_order=ctx.provider_order,
        story_card=story_card_for_rewrite,
    )
    ctx.german_master.seo_title = seo.seo_title
    ctx.german_master.meta_description = seo.meta_description
    ctx.german_master.slug = seo.slug
    ctx.german_master.focus_keywords = seo.keywords
    if seo.success:
        _record_ai_runtime(ctx, "seo_de", seo.provider, seo.model, getattr(seo, "tokens", 0))


# ---------------------------------------------------------------------------
# Per-block regeneration
# ---------------------------------------------------------------------------

async def _regen_title(ctx: PipelineContext) -> None:
    existing = ctx.request.existing_payload
    body_de = existing.get("body_de", "")
    phrases = existing.get("key_phrases", [])
    rewrite = await rewrite_to_german(
        original_title=existing.get("title_de", ""),
        original_content=body_de,
        source_language="de",
        content_type=existing.get("content_type", "news"),
        key_phrases=phrases,
        openai_api_key=ctx.openai_key,
        deepseek_api_key=ctx.deepseek_key,
        provider_order=ctx.provider_order,
        length_profile="brief",
    )
    if rewrite.success:
        ctx.german_master = LanguagePackage(lang="de", title=rewrite.title_de,
                                             excerpt=existing.get("lead_de", ""),
                                             content=body_de)


async def _regen_lead(ctx: PipelineContext) -> None:
    existing = ctx.request.existing_payload
    rewrite = await rewrite_to_german(
        original_title=existing.get("title_de", ""),
        original_content=existing.get("body_de", ""),
        source_language="de",
        content_type=existing.get("content_type", "news"),
        key_phrases=existing.get("key_phrases", []),
        openai_api_key=ctx.openai_key,
        deepseek_api_key=ctx.deepseek_key,
        provider_order=ctx.provider_order,
        length_profile="brief",
    )
    if rewrite.success:
        ctx.german_master = LanguagePackage(lang="de", title=existing.get("title_de", ""),
                                             excerpt=rewrite.lead_de,
                                             content=existing.get("body_de", ""))


async def _regen_body(ctx: PipelineContext) -> None:
    req = ctx.request
    existing = req.existing_payload
    ctx.semantic = semantic_analyze(
        title=existing.get("title_de", req.original_title),
        content=req.original_content or req.original_excerpt,
        hint_lang=req.source_language,
    )
    rewrite = await rewrite_to_german(
        original_title=req.original_title,
        original_content=req.original_content or req.original_excerpt,
        source_language=ctx.semantic.detected_language,
        content_type=ctx.semantic.content_type,
        key_phrases=ctx.semantic.key_phrases,
        openai_api_key=ctx.openai_key,
        deepseek_api_key=ctx.deepseek_key,
        provider_order=ctx.provider_order,
        length_profile=req.length_profile,
    )
    if rewrite.success:
        ctx.german_master = LanguagePackage(lang="de",
                                             title=existing.get("title_de", rewrite.title_de),
                                             excerpt=existing.get("lead_de", rewrite.lead_de),
                                             content=rewrite.body_de)


async def _regen_media(ctx: PipelineContext) -> None:
    req = ctx.request
    existing = req.existing_payload
    phrases = existing.get("key_phrases", [req.original_title])
    ctx.featured_media = await find_media(
        key_phrases=phrases,
        source_url=req.original_url,
        pexels_api_key=ctx.pexels_key,
        query_lang="de",
    )
    # Preserve existing DE master content
    ctx.german_master = LanguagePackage(
        lang="de",
        title=existing.get("title_de", ""),
        excerpt=existing.get("lead_de", ""),
        content=existing.get("body_de", ""),
    )


async def _regen_seo(ctx: PipelineContext) -> None:
    existing = ctx.request.existing_payload
    regen_card = None
    if isinstance(existing, dict):
        meta = existing.get("_meta") or {}
        if isinstance(meta, dict):
            sc = meta.get("story_card")
            if isinstance(sc, dict) and sc:
                regen_card = sc
    seo = await generate_seo(
        title_de=existing.get("title_de", ""),
        lead_de=existing.get("lead_de", ""),
        body_de=existing.get("body_de", ""),
        key_phrases=existing.get("key_phrases", []),
        openai_api_key=ctx.openai_key,
        deepseek_api_key=ctx.deepseek_key,
        provider_order=ctx.provider_order,
        story_card=regen_card,
    )
    ctx.german_master = LanguagePackage(
        lang="de",
        title=existing.get("title_de", ""),
        excerpt=existing.get("lead_de", ""),
        content=existing.get("body_de", ""),
        seo_title=seo.seo_title,
        meta_description=seo.meta_description,
        slug=seo.slug,
        focus_keywords=seo.keywords,
    )


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

async def _search_supporting_sources(key_phrases: list[str], primary_url: str) -> list[str]:
    """Fetch URLs of 2-3 supporting sources via Google News RSS.

    Kept for backward compatibility — returns plain URLs. Prefer
    ``_search_supporting_sources_rich`` for new callers (returns dicts
    with url+title+domain so the rewriter can name each source).
    """
    rich = await _search_supporting_sources_rich(key_phrases, primary_url, limit=3)
    return [entry["url"] for entry in rich]


async def _search_supporting_sources_rich(
    key_phrases: list[str],
    primary_url: str,
    limit: int = 5,
) -> list[dict[str, str]]:
    """Phase 2.7 — return structured supporting-source entries for the
    rewriter dossier.

    Each entry has ``url``, ``title`` and ``domain``. The rewriter prompt
    consumes these via dossier_block so it can write multi-source
    synthesis with named attribution per fact.

    Implementation: parses Google News RSS items rather than just <link>
    tags. We extract title, source name (the publisher), and primary URL.
    Domain is derived from the URL host. Same-domain duplicates and the
    primary URL itself are filtered out so the rewriter sees only items
    that actually add something.
    """
    if not key_phrases:
        return []
    try:
        import httpx
        query = " ".join(key_phrases[:3])
        url = f"https://news.google.com/rss/search?q={query}&hl=de&gl=DE&ceid=DE:de"
        async with httpx.AsyncClient(timeout=12) as client:
            resp = await client.get(url, headers={"User-Agent": "Mozilla/5.0"})
        if resp.status_code != 200:
            return []
        import re
        # Each <item>...</item> block has <title>, <link>, <source url="...">.
        items = re.findall(r"<item>(.*?)</item>", resp.text, flags=re.S)
        primary_host = ""
        try:
            primary_host = urlparse(primary_url).netloc.lower()
        except Exception:
            pass

        seen_hosts: set[str] = set()
        if primary_host:
            seen_hosts.add(primary_host)

        result: list[dict[str, str]] = []
        for block in items:
            link_match = re.search(r"<link>([^<]+)</link>", block)
            title_match = re.search(r"<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</title>", block, flags=re.S)
            source_match = re.search(r"<source[^>]*>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</source>", block, flags=re.S)
            if not link_match:
                continue
            link = html.unescape(link_match.group(1).strip())
            if link == primary_url or not _usable_supporting_url(link):
                continue
            try:
                host = urlparse(link).netloc.lower()
            except Exception:
                continue
            if not host or host in seen_hosts:
                continue
            seen_hosts.add(host)
            title = html.unescape((title_match.group(1) if title_match else "").strip())
            source_name = html.unescape((source_match.group(1) if source_match else "").strip())
            domain = source_name or host.replace("www.", "")
            result.append({
                "url": link,
                "title": title,
                "domain": domain,
            })
            if len(result) >= limit:
                break
        return result
    except Exception:
        return []


def _clean_original_text(raw: str) -> str:
    """Drop feed HTML/media artifacts so captions/alt text do not become facts."""
    text = re.sub(r"<img\b[^>]*>", " ", raw or "", flags=re.I | re.S)
    text = re.sub(r"<figure\b.*?</figure>", " ", text, flags=re.I | re.S)
    text = re.sub(r"<[^>]+>", " ", text)
    text = html.unescape(text)
    return re.sub(r"\s+", " ", text).strip()


def _language_package_complete(package: LanguagePackage) -> bool:
    return all([
        bool((package.title or "").strip()),
        bool((package.excerpt or "").strip()),
        bool((package.content or "").strip()),
    ])


def _usable_supporting_url(url: str) -> bool:
    try:
        parsed = urlparse(url)
    except Exception:
        return False
    host = parsed.netloc.lower()
    path = parsed.path.rstrip("/")
    if not host or not parsed.scheme.startswith("http"):
        return False
    if host.endswith("news.google.com") and path in {"", "/search"}:
        return False
    return True


def _build_response(ctx: PipelineContext, outcome: str) -> WorkerResponse:
    from dataclasses import asdict
    media_candidates = []
    if ctx.featured_media and ctx.featured_media.image_url:
        media_candidates = [MediaCandidate(
            url=ctx.featured_media.image_url,
            source_url=ctx.featured_media.attribution,
            source_name=ctx.featured_media.image_source,
            kind="image",
            relevance_reason=ctx.featured_media.alt_text,
        )]

    return WorkerResponse(
        queue_id=ctx.request.queue_id,
        outcome=outcome,
        story_kind=ctx.request.story_kind,
        length_profile=ctx.effective_length_profile or ctx.request.length_profile,
        categories=ctx.categories,
        german_master=ctx.german_master,
        ukrainian=ctx.ukrainian,
        english=ctx.english,
        media_candidates=media_candidates,
        featured_media_url=ctx.featured_media.image_url if ctx.featured_media else "",
        tags=ctx.tags,
        quality={"semantic_score": ctx.semantic.quality_score, "word_count": ctx.semantic.word_count},
        warnings=ctx.warnings,
        blockers=ctx.blockers,
        source_dossier=ctx.source_dossier,
        ai_runtime=ctx.ai_runtime,
    )


def build_normalized_payload(response: WorkerResponse) -> dict:
    featured_media_url = response.featured_media_url or ""
    languages = {
        "de": {
            "lang": "de",
            "title": response.german_master.title,
            "excerpt": response.german_master.excerpt,
            "content": response.german_master.content,
            "seo_title": response.german_master.seo_title,
            "meta_description": response.german_master.meta_description,
            "slug": response.german_master.slug,
            "focus_keywords": list(response.german_master.focus_keywords or []),
            "media_url": featured_media_url,
        },
        "uk": {
            "lang": "uk",
            "title": response.ukrainian.title,
            "excerpt": response.ukrainian.excerpt,
            "content": response.ukrainian.content,
            "seo_title": response.ukrainian.seo_title,
            "meta_description": response.ukrainian.meta_description,
            "slug": response.ukrainian.slug,
            "focus_keywords": list(response.ukrainian.focus_keywords or []),
            "media_url": featured_media_url,
        },
        "en": {
            "lang": "en",
            "title": response.english.title,
            "excerpt": response.english.excerpt,
            "content": response.english.content,
            "seo_title": response.english.seo_title,
            "meta_description": response.english.meta_description,
            "slug": response.english.slug,
            "focus_keywords": list(response.english.focus_keywords or []),
            "media_url": featured_media_url,
        },
    }

    media_candidates = [candidate.url for candidate in response.media_candidates if candidate.url]
    runtime = list(response.ai_runtime or [])
    primary_runtime = next((item for item in runtime if item.get("stage") == "rewrite_de"), runtime[0] if runtime else {})
    primary_provider = str(primary_runtime.get("provider", ""))
    fallback_provider = next(
        (str(item.get("provider", "")) for item in runtime if item.get("provider") and item.get("provider") != primary_provider),
        "",
    )
    total_tokens = 0
    for entry in runtime:
        if isinstance(entry, dict):
            try:
                total_tokens += max(0, int(entry.get("tokens", 0) or 0))
            except (TypeError, ValueError):
                pass
    meta = {
        "provider": primary_provider,
        "model": str(primary_runtime.get("model", "")),
        "ai_runtime": runtime,
        "tokens": total_tokens,
        "fallback_provider_used": fallback_provider,
        "source_dossier": response.source_dossier or {},
        "source_count": 1 + len((response.source_dossier or {}).get("supporting", []) or []),
        "event_context": response.event_context or {},
        "quality": response.quality or {},
        "seo_quality": response.seo_quality or {},
        "release_quality": response.release_quality or {},
        "google_quality": response.google_quality or {},
        "warnings": list(response.warnings or []),
        "blockers": list(response.blockers or []),
        "canonical_language": "de",
    }

    return {
        "languages": languages,
        "categories": list(response.categories or []),
        "tags": list(response.tags or []),
        "media_url": featured_media_url,
        "featured_media_url": featured_media_url,
        "inline_media_urls": media_candidates[:4],
        "source_block": response.source_dossier or {},
        "_meta": meta,
    }


def _record_ai_runtime(ctx: PipelineContext, stage: str, provider: str, model: str, tokens: int = 0) -> None:
    provider = (provider or "").strip()
    model = (model or "").strip()
    if not provider and not model:
        return
    entry = {"stage": stage, "provider": provider, "model": model}
    try:
        tokens_int = max(0, int(tokens))
    except (TypeError, ValueError):
        tokens_int = 0
    if tokens_int:
        entry["tokens"] = tokens_int
    ctx.ai_runtime.append(entry)
