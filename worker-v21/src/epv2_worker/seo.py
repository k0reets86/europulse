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

logger = logging.getLogger(__name__)


@dataclass
class SEOResult:
    seo_title: str = ""
    meta_description: str = ""
    slug: str = ""
    keywords: list[str] = field(default_factory=list)
    success: bool = False


_SYSTEM = """Du bist ein SEO-Experte für die Nachrichtenplattform EuroPulse.today.
Generiere auf Basis des Artikels ein JSON mit diesen Feldern:
- seo_title: 50–60 Zeichen, enthält Hauptkeyword, kein Clickbait
- meta_description: 140–160 Zeichen, Zusammenfassung mit Call-to-Read
- slug: URL-freundlich, Kleinbuchstaben, Bindestriche, max 60 Zeichen, keine Umlaute
- keywords: Array mit 5–8 relevanten Suchbegriffen
Ausgabe nur als JSON."""


async def generate_seo(
    title_de: str,
    lead_de: str,
    body_de: str,
    key_phrases: list[str],
    openai_api_key: str,
    deepseek_api_key: str = "",
) -> SEOResult:
    user = f"""Artikel-Titel: {title_de}
Teaser: {lead_de}
Schlüsselbegriffe: {", ".join(key_phrases)}
Artikelanfang: {body_de[:800]}

Erstelle SEO-Metadaten."""

    if openai_api_key:
        result = await _call(user, openai_api_key, "openai")
        if result.success:
            return result
    if deepseek_api_key:
        result = await _call(user, deepseek_api_key, "deepseek")
        if result.success:
            return result

    # Graceful fallback: derive from title
    slug = _slugify(title_de)
    return SEOResult(
        seo_title=title_de[:60],
        meta_description=lead_de[:155],
        slug=slug,
        keywords=key_phrases[:6],
        success=True,
    )


async def _call(user_prompt: str, api_key: str, provider: str) -> SEOResult:
    try:
        base_url = "https://api.deepseek.com/v1" if provider == "deepseek" else None
        model    = "deepseek-chat" if provider == "deepseek" else "gpt-4o-mini"
        kwargs   = {"base_url": base_url} if base_url else {}
        client   = AsyncOpenAI(api_key=api_key, **kwargs)
        resp = await client.chat.completions.create(
            model=model,
            messages=[
                {"role": "system", "content": _SYSTEM},
                {"role": "user",   "content": user_prompt},
            ],
            response_format={"type": "json_object"},
            temperature=0.2,
            max_tokens=512,
        )
        raw = resp.choices[0].message.content or ""
        data = json.loads(raw)
        return SEOResult(
            seo_title=str(data.get("seo_title", ""))[:70],
            meta_description=str(data.get("meta_description", ""))[:170],
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
