"""
EuroPulse AutoPilot v2.1 — DE Master Rewriter
Rewrites article into German using OpenAI GPT-4o Mini (primary) or DeepSeek (fallback).
"""
from __future__ import annotations

import logging
from dataclasses import dataclass

from openai import AsyncOpenAI

logger = logging.getLogger(__name__)


@dataclass
class RewriteResult:
    title_de: str = ""
    lead_de: str = ""
    body_de: str = ""
    success: bool = False
    error: str = ""


_SYSTEM_PROMPT = """Du bist ein professioneller deutschsprachiger Nachrichtenredakteur für EuroPulse.today.
Schreibe sachliche, neutrale Artikel auf Hochdeutsch. Keine Meinungen, keine reißerischen Überschriften.
Journalistische Sprache: klare Aussagen, Passiv wo angebracht, Quellenangaben wie "nach Angaben von...".
Ausgabe ausschließlich als gültiges JSON mit den Feldern: title, lead, body."""


async def rewrite_to_german(
    original_title: str,
    original_content: str,
    source_language: str,
    content_type: str,
    key_phrases: list[str],
    openai_api_key: str,
    deepseek_api_key: str = "",
    length_profile: str = "standard",
) -> RewriteResult:
    """Rewrite to German master. Falls back to DeepSeek if OpenAI fails."""
    length_hint = {
        "brief":    "250–400 Wörter im body",
        "standard": "400–700 Wörter im body",
        "long":     "700–1200 Wörter im body",
    }.get(length_profile, "400–700 Wörter im body")

    user_prompt = f"""Quellensprache: {source_language}
Content-Typ: {content_type}
Schlüsselbegriffe: {", ".join(key_phrases)}
Ziel-Länge: {length_hint}

ORIGINAL TITEL:
{original_title}

ORIGINAL INHALT:
{original_content[:4000]}

Erstelle einen professionellen deutschen Nachrichtenartikel.
Gib zurück: {{"title": "...", "lead": "ein Satz / 1–2 Sätze Teaser", "body": "vollständiger Artikel"}}"""

    # Try OpenAI first
    if openai_api_key:
        result = await _call_openai(user_prompt, openai_api_key)
        if result.success:
            return result

    # Fallback: DeepSeek
    if deepseek_api_key:
        result = await _call_deepseek(user_prompt, deepseek_api_key)
        if result.success:
            return result

    return RewriteResult(error="All AI providers failed")


async def _call_openai(user_prompt: str, api_key: str) -> RewriteResult:
    try:
        client = AsyncOpenAI(api_key=api_key)
        response = await client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[
                {"role": "system", "content": _SYSTEM_PROMPT},
                {"role": "user",   "content": user_prompt},
            ],
            response_format={"type": "json_object"},
            temperature=0.3,
            max_tokens=2048,
        )
        raw = response.choices[0].message.content or ""
        return _parse_json_result(raw)
    except Exception as exc:
        logger.warning("OpenAI rewrite failed: %s", exc)
        return RewriteResult(error=str(exc))


async def _call_deepseek(user_prompt: str, api_key: str) -> RewriteResult:
    try:
        client = AsyncOpenAI(
            api_key=api_key,
            base_url="https://api.deepseek.com/v1",
        )
        response = await client.chat.completions.create(
            model="deepseek-chat",
            messages=[
                {"role": "system", "content": _SYSTEM_PROMPT},
                {"role": "user",   "content": user_prompt},
            ],
            response_format={"type": "json_object"},
            temperature=0.3,
            max_tokens=2048,
        )
        raw = response.choices[0].message.content or ""
        return _parse_json_result(raw)
    except Exception as exc:
        logger.warning("DeepSeek rewrite failed: %s", exc)
        return RewriteResult(error=str(exc))


def _parse_json_result(raw: str) -> RewriteResult:
    import json
    try:
        data = json.loads(raw)
        return RewriteResult(
            title_de=str(data.get("title", "")).strip(),
            lead_de=str(data.get("lead", "")).strip(),
            body_de=str(data.get("body", "")).strip(),
            success=True,
        )
    except Exception as exc:
        return RewriteResult(error=f"JSON parse failed: {exc}")
