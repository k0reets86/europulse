from __future__ import annotations

from dataclasses import dataclass, field

from .contracts import LanguagePackage, MediaCandidate, WorkerRequest, WorkerResponse


@dataclass(slots=True)
class PipelineContext:
    request: WorkerRequest
    context_signals: dict[str, str] = field(default_factory=dict)
    source_dossier: dict = field(default_factory=dict)
    event_context: dict = field(default_factory=dict)
    categories: list[str] = field(default_factory=list)
    tags: list[str] = field(default_factory=list)
    media_candidates: list[MediaCandidate] = field(default_factory=list)
    featured_media_url: str = ""
    german_master: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="de"))
    ukrainian: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="uk"))
    english: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="en"))
    warnings: list[str] = field(default_factory=list)
    blockers: list[str] = field(default_factory=list)


def run_pipeline(request: WorkerRequest) -> WorkerResponse:
    ctx = PipelineContext(request=request)
    understand_context(ctx)
    enrich_sources(ctx)
    assign_category(ctx)
    resolve_media_candidates(ctx)
    build_german_master(ctx)
    validate_german_master(ctx)
    if ctx.blockers:
        return build_response(ctx, outcome="ready_review")
    translate_from_german_master(ctx)
    validate_multilingual_bundle(ctx)
    return build_response(ctx, outcome=final_outcome(ctx))


def understand_context(ctx: PipelineContext) -> None:
    text = " ".join(
        filter(
            None,
            [
                ctx.request.original_title,
                ctx.request.original_excerpt,
                ctx.request.original_content,
                ctx.request.category_proposed,
                ctx.request.story_format,
            ],
        )
    ).lower()
    kind = "news"
    if any(token in text for token in ("streik", "jobcenter", "aufenthalt", "beratung", "mvg", "s-bahn", "u-bahn")):
        kind = "service"
    elif any(token in text for token in ("spiel", "match", "liga", "trainer", "tor", "dfb", "uefa")):
        kind = "sport"
    elif any(token in text for token in ("show", "film", "kultur", "tv", "festival", "serie", "konzert")):
        kind = "kultur"
    elif any(token in text for token in ("community", "спільнота", "verein", "diaspora", "українц")):
        kind = "community"

    story_kind = ctx.request.story_kind or kind or "news"
    ctx.context_signals = {
        "kind": kind,
        "story_kind": story_kind,
        "length_profile": ctx.request.length_profile or "standard",
        "source_language": ctx.request.source_language or "unknown",
        "story_format": ctx.request.story_format or "news",
    }


def enrich_sources(ctx: PipelineContext) -> None:
    ctx.source_dossier = {
        "primary": {
            "title": ctx.request.original_title,
            "excerpt": ctx.request.original_excerpt,
            "content": ctx.request.original_content,
            "url": ctx.request.original_url,
            "date": ctx.request.original_date,
        },
        "supporting": [],
    }
    ctx.event_context = {
        "kind": ctx.context_signals.get("kind", "news"),
        "location": "",
        "starts_at": "",
        "participants": [],
    }


def assign_category(ctx: PipelineContext) -> None:
    category = (ctx.request.category_proposed or ctx.request.category_final or "").strip().lower()
    kind = ctx.context_signals.get("kind", "news")
    if not category:
        category = {
            "service": "leben-in-deutschland",
            "sport": "sport",
            "kultur": "kultur",
            "community": "community",
        }.get(kind, "deutschland")
    ctx.categories = [category]


def resolve_media_candidates(ctx: PipelineContext) -> None:
    if ctx.request.source_image_url:
        ctx.media_candidates.append(
            MediaCandidate(
                url=ctx.request.source_image_url,
                source_url=ctx.request.original_url,
                source_name="primary",
                relevance_reason="primary-source-media",
            )
        )
        ctx.featured_media_url = ctx.request.source_image_url


def build_german_master(ctx: PipelineContext) -> None:
    title = ctx.request.original_title.strip()
    excerpt = ctx.request.original_excerpt.strip()
    content = ctx.request.original_content.strip()
    if not title:
        ctx.blockers.append("missing_title")
    if not content and excerpt:
        content = excerpt
    ctx.german_master = LanguagePackage(
        lang="de",
        title=title,
        excerpt=excerpt,
        content=content,
        seo_title=title,
        meta_description=excerpt,
        slug="",
        focus_keywords=[],
    )


def validate_german_master(ctx: PipelineContext) -> None:
    title_len = len(ctx.german_master.title.strip())
    content_len = len(ctx.german_master.content.strip())
    story_kind = ctx.context_signals.get("story_kind", "news")
    if title_len < 8:
        ctx.blockers.append("de_title_too_short")
    if story_kind in {"note", "service_alert", "brief"}:
        min_content = 20
    elif story_kind in {"analysis", "feature"}:
        min_content = 160
    else:
        min_content = 40
    if content_len < min_content:
        ctx.warnings.append("de_master_is_short")
    if not ctx.featured_media_url:
        ctx.warnings.append("shared_media_missing")


def translate_from_german_master(ctx: PipelineContext) -> None:
    # Placeholder translations. The important thing in phase 1 is enforcing
    # the DE-first contract and one shared bundle shape.
    de = ctx.german_master
    ctx.ukrainian = LanguagePackage(
        lang="uk",
        title=de.title,
        excerpt=de.excerpt,
        content=de.content,
        seo_title=de.seo_title,
        meta_description=de.meta_description,
        slug="",
        focus_keywords=list(de.focus_keywords),
    )
    ctx.english = LanguagePackage(
        lang="en",
        title=de.title,
        excerpt=de.excerpt,
        content=de.content,
        seo_title=de.seo_title,
        meta_description=de.meta_description,
        slug="",
        focus_keywords=list(de.focus_keywords),
    )


def validate_multilingual_bundle(ctx: PipelineContext) -> None:
    if not ctx.categories:
        ctx.blockers.append("missing_category")
    if not ctx.german_master.title:
        ctx.blockers.append("german_master_missing")


def final_outcome(ctx: PipelineContext) -> str:
    if ctx.blockers:
        return "ready_review"
    if ctx.warnings:
        return "retry_process"
    return "ready_publish"


def build_response(ctx: PipelineContext, outcome: str) -> WorkerResponse:
    quality_score = 100 if not ctx.blockers and not ctx.warnings else 84 if not ctx.blockers else 58
    release_score = 100 if not ctx.blockers and ctx.featured_media_url else 78 if not ctx.blockers else 52
    seo_score = 100 if ctx.german_master.meta_description else 80
    google_score = 100 if ctx.german_master.meta_description else 82

    return WorkerResponse(
        queue_id=ctx.request.queue_id,
        outcome=outcome,
        story_kind=ctx.context_signals.get("story_kind", "news"),
        length_profile=ctx.context_signals.get("length_profile", "standard"),
        categories=list(ctx.categories),
        german_master=ctx.german_master,
        ukrainian=ctx.ukrainian,
        english=ctx.english,
        source_dossier=ctx.source_dossier,
        event_context=ctx.event_context,
        media_candidates=list(ctx.media_candidates),
        featured_media_url=ctx.featured_media_url,
        tags=list(ctx.tags),
        quality={"score": quality_score, "pass": not ctx.blockers, "warnings": list(ctx.warnings)},
        seo_quality={"score": seo_score, "pass": seo_score == 100, "warnings": [] if seo_score == 100 else ["meta_description_missing_or_weak"]},
        release_quality={"score": release_score, "pass": release_score == 100, "warnings": [] if release_score == 100 else ["shared_featured_media_missing"]},
        google_quality={"score": google_score, "pass": google_score == 100, "warnings": [] if google_score == 100 else ["snippet_packaging_not_complete"]},
        warnings=list(ctx.warnings),
        blockers=list(ctx.blockers),
    )
