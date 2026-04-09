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
import logging
from dataclasses import dataclass, field

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

    @property
    def openai_key(self) -> str:
        return str(self.request.editorial_flags.get("openai_api_key", ""))

    @property
    def deepseek_key(self) -> str:
        return str(self.request.editorial_flags.get("deepseek_api_key", ""))

    @property
    def pexels_key(self) -> str:
        return str(self.request.editorial_flags.get("pexels_api_key", ""))


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

async def _run_full_bundle(ctx: PipelineContext) -> None:
    req = ctx.request

    # 1. Semantic analysis
    ctx.semantic = semantic_analyze(
        title=req.original_title,
        content=req.original_content or req.original_excerpt,
        hint_lang=req.source_language,
    )
    ctx.categories = [req.category_proposed] if req.category_proposed else []
    ctx.tags = ctx.semantic.key_phrases[:5]

    # 2. Enrich sources if needed (fetch supporting content)
    supporting_urls: list[str] = []
    if ctx.semantic.needs_enrichment:
        supporting_urls = await _search_supporting_sources(
            ctx.semantic.key_phrases, req.original_url
        )

    # 3. Rewrite to German
    rewrite = await rewrite_to_german(
        original_title=req.original_title,
        original_content=req.original_content or req.original_excerpt,
        source_language=ctx.semantic.detected_language,
        content_type=ctx.semantic.content_type,
        key_phrases=ctx.semantic.key_phrases,
        openai_api_key=ctx.openai_key,
        deepseek_api_key=ctx.deepseek_key,
        length_profile=req.length_profile,
    )
    if not rewrite.success:
        ctx.blockers.append(f"Rewrite failed: {rewrite.error}")
        return

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
        )
    )
    en_task = asyncio.create_task(
        translate_from_german(
            rewrite.title_de, rewrite.lead_de, rewrite.body_de,
            target_lang="English",
            openai_api_key=ctx.openai_key,
            deepseek_api_key=ctx.deepseek_key,
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
        ctx.warnings.append(f"UK translation failed: {uk_result.error}")
    if not en_result.success:
        ctx.warnings.append(f"EN translation failed: {en_result.error}")

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
    )
    ctx.german_master.seo_title = seo.seo_title
    ctx.german_master.meta_description = seo.meta_description
    ctx.german_master.slug = seo.slug
    ctx.german_master.focus_keywords = seo.keywords


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
    seo = await generate_seo(
        title_de=existing.get("title_de", ""),
        lead_de=existing.get("lead_de", ""),
        body_de=existing.get("body_de", ""),
        key_phrases=existing.get("key_phrases", []),
        openai_api_key=ctx.openai_key,
        deepseek_api_key=ctx.deepseek_key,
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
    """Fetch URLs of 2-3 supporting sources via Google News RSS."""
    if not key_phrases:
        return []
    try:
        import httpx
        query = " ".join(key_phrases[:3])
        url = f"https://news.google.com/rss/search?q={query}&hl=de&gl=DE&ceid=DE:de"
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.get(url, headers={"User-Agent": "Mozilla/5.0"})
        if resp.status_code != 200:
            return []
        import re
        urls = re.findall(r"<link>https://[^<]+</link>", resp.text)
        result = [u.replace("<link>", "").replace("</link>", "") for u in urls[:4]]
        return [u for u in result if u != primary_url][:3]
    except Exception:
        return []


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
        length_profile=ctx.request.length_profile,
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
    meta = {
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
