from __future__ import annotations

from dataclasses import dataclass, field, asdict
from typing import Any


# 2026-05-13: dead_letter удалён из набора. Pipeline никогда его не emit'ит
# (только ready_publish, ready_review, retry_process), а PHP side не имел
# handler — silent "success" branch. Schema lie устранён.
WORKER_OUTCOMES = {"ready_publish", "ready_review", "retry_process"}


@dataclass(slots=True)
class LanguagePackage:
    lang: str
    title: str = ""
    excerpt: str = ""
    card_lead: str = ""
    content: str = ""
    seo_title: str = ""
    meta_description: str = ""
    slug: str = ""
    focus_keywords: list[str] = field(default_factory=list)


@dataclass(slots=True)
class MediaCandidate:
    url: str
    source_url: str = ""
    source_name: str = ""
    kind: str = "image"
    relevance_reason: str = ""


@dataclass(slots=True)
class WorkerRequest:
    queue_id: int
    stage: str = "full_bundle"
    story_kind: str = "news"
    length_profile: str = "standard"
    original_title: str = ""
    original_excerpt: str = ""
    original_content: str = ""
    original_url: str = ""
    original_date: str = ""
    source_image_url: str = ""
    source_language: str = ""
    category_proposed: str = ""
    category_final: str = ""
    story_format: str = ""
    topic_label: str = ""
    cluster_id: int = 0
    editorial_flags: dict[str, Any] = field(default_factory=dict)
    existing_payload: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "WorkerRequest":
        return cls(
            queue_id=int(data.get("queue_id") or 0),
            stage=str(data.get("stage") or "full_bundle"),
            story_kind=str(data.get("story_kind") or "news"),
            length_profile=str(data.get("length_profile") or "standard"),
            original_title=str(data.get("original_title") or ""),
            original_excerpt=str(data.get("original_excerpt") or ""),
            original_content=str(data.get("original_content") or ""),
            original_url=str(data.get("original_url") or ""),
            original_date=str(data.get("original_date") or ""),
            source_image_url=str(data.get("source_image_url") or ""),
            source_language=str(data.get("source_language") or ""),
            category_proposed=str(data.get("category_proposed") or ""),
            category_final=str(data.get("category_final") or ""),
            story_format=str(data.get("story_format") or ""),
            topic_label=str(data.get("topic_label") or ""),
            cluster_id=int(data.get("cluster_id") or 0),
            editorial_flags=dict(data.get("editorial_flags") or {}),
            existing_payload=dict(data.get("existing_payload") or {}),
        )


@dataclass(slots=True)
class WorkerResponse:
    queue_id: int
    outcome: str
    story_kind: str = "news"
    length_profile: str = "standard"
    categories: list[str] = field(default_factory=list)
    german_master: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="de"))
    ukrainian: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="uk"))
    english: LanguagePackage = field(default_factory=lambda: LanguagePackage(lang="en"))
    source_dossier: dict[str, Any] = field(default_factory=dict)
    event_context: dict[str, Any] = field(default_factory=dict)
    media_candidates: list[MediaCandidate] = field(default_factory=list)
    featured_media_url: str = ""
    tags: list[str] = field(default_factory=list)
    quality: dict[str, Any] = field(default_factory=dict)
    seo_quality: dict[str, Any] = field(default_factory=dict)
    release_quality: dict[str, Any] = field(default_factory=dict)
    google_quality: dict[str, Any] = field(default_factory=dict)
    warnings: list[str] = field(default_factory=list)
    blockers: list[str] = field(default_factory=list)
    ai_runtime: list[dict[str, Any]] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        payload = asdict(self)
        payload["german_master"] = asdict(self.german_master)
        payload["ukrainian"] = asdict(self.ukrainian)
        payload["english"] = asdict(self.english)
        payload["media_candidates"] = [asdict(candidate) for candidate in self.media_candidates]
        return payload


# --- v2.1 additions ---

@dataclass
class SemanticInfo:
    detected_language: str = "de"
    key_phrases: list[str] = field(default_factory=list)
    quality_score: float = 0.0
    content_type: str = "news"
    needs_enrichment: bool = False
    word_count: int = 0
    sentence_count: int = 0
