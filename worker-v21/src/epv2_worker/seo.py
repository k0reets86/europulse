"""
EuroPulse AutoPilot v2.1 — SEO Generator
Generates SEO title, meta description, URL slug, and keyword list.
"""
from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass, field

from openai import AsyncOpenAI

from .openai_compat import completion_debug, completion_text, reasoning_extra_body

logger = logging.getLogger(__name__)


@dataclass
class SEOResult:
    seo_title: str = ""
    meta_description: str = ""
    slug: str = ""
    keywords: list[str] = field(default_factory=list)
    success: bool = False
    provider: str = ""
    model: str = ""


_SYSTEM = """Du bist ein SEO-Experte für die Nachrichtenplattform EuroPulse.today.
Generiere auf Basis des Artikels ein JSON mit diesen Feldern:
- seo_title: 50–60 Zeichen, enthält Hauptkeyword, kein Clickbait
- meta_description: 140–160 Zeichen, Zusammenfassung mit Call-to-Read
- slug: URL-freundlich, Kleinbuchstaben, Bindestriche, max 60 Zeichen, keine Umlaute
- keywords: Array mit 5–8 relevanten Suchbegriffen
Erfinde keine Vornamen, Rollen oder Funktionen. Wenn der Artikel nur „Söder" oder „Miersch" nennt, schreibe nicht „Markus Söder", „Matthias Miersch" oder ähnliche Ergänzungen.
Ausgabe nur als JSON."""


async def generate_seo(
    title_de: str,
    lead_de: str,
    body_de: str,
    key_phrases: list[str],
    openai_api_key: str,
    deepseek_api_key: str = "",
    provider_order: list[tuple[str, str, str]] | None = None,
) -> SEOResult:
    user = f"""Artikel-Titel: {title_de}
Teaser: {lead_de}
Schlüsselbegriffe: {", ".join(key_phrases)}
Artikelanfang: {body_de[:800]}

Erstelle SEO-Metadaten."""

    candidates = provider_order or [
        ("openai", openai_api_key, "gpt-4o-mini"),
        ("deepseek", deepseek_api_key, "deepseek-chat"),
    ]
    for provider, api_key, model in candidates:
        if not api_key:
            continue
        result = await _call(user, api_key, provider, model)
        if result.success:
            result.provider = provider
            result.model = model or ("deepseek-chat" if provider == "deepseek" else "gpt-4o-mini")
            return result

    # Graceful fallback: derive from title
    slug = _slugify(title_de)
    return SEOResult(
        seo_title=title_de[:60],
        meta_description=lead_de[:155],
        slug=slug,
        keywords=key_phrases[:6],
        success=True,
        provider="heuristic",
        model="",
    )


async def _call(user_prompt: str, api_key: str, provider: str, model: str) -> SEOResult:
    try:
        base_url = "https://api.deepseek.com/v1" if provider == "deepseek" else None
        model    = model or ("deepseek-chat" if provider == "deepseek" else "gpt-4o-mini")
        kwargs   = {"base_url": base_url} if base_url else {}
        client   = AsyncOpenAI(api_key=api_key, **kwargs)
        kwargs = {
            "model": model,
            "messages": [
                {"role": "system", "content": _SYSTEM},
                {"role": "user",   "content": user_prompt},
            ],
            "response_format": {"type": "json_object"},
        }
        if provider != "deepseek" and model.startswith(("gpt-5", "o")):
            kwargs["extra_body"] = reasoning_extra_body(model, 2048)
        else:
            kwargs["temperature"] = 0.2
            kwargs["max_tokens"] = 512
        resp = await client.chat.completions.create(**kwargs)
        raw = completion_text(resp)
        if not raw.strip():
            raise ValueError(f"empty {provider} response ({completion_debug(resp)})")
        data = json.loads(raw)
        seo_title = _strip_unsupported_person_expansions(str(data.get("seo_title", "")), user_prompt)
        meta_description = _strip_unsupported_person_expansions(str(data.get("meta_description", "")), user_prompt)
        return SEOResult(
            seo_title=seo_title[:70],
            meta_description=meta_description[:170],
            slug=_slugify(str(data.get("slug", ""))),
            keywords=[str(k) for k in data.get("keywords", [])[:10]],
            success=True,
        )
    except Exception as exc:
        logger.warning("SEO generation via %s failed: %s", provider, exc)
        return SEOResult()


def _slugify(text: str) -> str:
    """Convert text to URL-safe slug."""
    replacements = {"ä":"ae","ö":"oe","ü":"ue","ß":"ss","à":"a","á":"a","â":"a",
                    "è":"e","é":"e","ê":"e","ì":"i","í":"i","ò":"o","ó":"o","ô":"o",
                    "ù":"u","ú":"u","û":"u","ї":"i","і":"i","є":"e","ш":"sh"}
    slug = text.lower()
    for src, dst in replacements.items():
        slug = slug.replace(src, dst)
    slug = re.sub(r"[^a-z0-9\s-]", "", slug)
    slug = re.sub(r"[\s_-]+", "-", slug).strip("-")
    return slug[:60]


def _strip_unsupported_person_expansions(text: str, source_text: str) -> str:
    cleaned = text
    if not re.search(r"\bMarkus\s+Söder\b", source_text or ""):
        cleaned = re.sub(r"\bMarkus\s+Söder\b", "Söder", cleaned)
    if not re.search(r"\b(?:Matthias|Johannes|Klaus)\s+Miersch\b", source_text or ""):
        cleaned = re.sub(r"\b(?:Matthias|Johannes|Klaus)\s+Miersch\b", "Miersch", cleaned)
    return cleaned
