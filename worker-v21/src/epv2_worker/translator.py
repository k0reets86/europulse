"""
EuroPulse AutoPilot v2.1 — Translator
Translates German master → Ukrainian + English.
"""
from __future__ import annotations

import json
import logging
from dataclasses import dataclass

from openai import AsyncOpenAI

logger = logging.getLogger(__name__)


@dataclass
class TranslationResult:
    title: str = ""
    lead: str = ""
    body: str = ""
    success: bool = False
    error: str = ""


_SYSTEM_PROMPT_TEMPLATE = """Du bist ein professioneller Übersetzer für die Nachrichtenplattform EuroPulse.today.
Übersetze den deutschen Nachrichtenartikel ins {target_lang}.
Erhalte journalistische Genauigkeit und Ton. Keine eigenen Ergänzungen.
Ausgabe ausschließlich als JSON: {{"title":"...","lead":"...","body":"..."}}"""


async def translate_from_german(
    title_de: str,
    lead_de: str,
    body_de: str,
    target_lang: str,   # "Ukrainian" or "English"
    openai_api_key: str,
    deepseek_api_key: str = "",
) -> TranslationResult:
    system = _SYSTEM_PROMPT_TEMPLATE.format(target_lang=target_lang)
    user = f"""TITEL (DE):\n{title_de}\n\nTEASER (DE):\n{lead_de}\n\nARTIKEL (DE):\n{body_de[:3000]}"""

    if openai_api_key:
        result = await _call(user, system, openai_api_key, "openai")
        if result.success:
            return result

    if deepseek_api_key:
        result = await _call(user, system, deepseek_api_key, "deepseek")
        if result.success:
            return result

    return TranslationResult(error="All providers failed")


async def _call(user_prompt: str, system_prompt: str, api_key: str, provider: str) -> TranslationResult:
    try:
        base_url = "https://api.deepseek.com/v1" if provider == "deepseek" else None
        model    = "deepseek-chat" if provider == "deepseek" else "gpt-4o-mini"
        kwargs   = {"base_url": base_url} if base_url else {}
        client   = AsyncOpenAI(api_key=api_key, **kwargs)

        resp = await client.chat.completions.create(
            model=model,
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user",   "content": user_prompt},
            ],
            response_format={"type": "json_object"},
            temperature=0.2,
            max_tokens=2048,
        )
        raw = resp.choices[0].message.content or ""
        data = json.loads(raw)
        return TranslationResult(
            title=str(data.get("title", "")).strip(),
            lead=str(data.get("lead", "")).strip(),
            body=str(data.get("body", "")).strip(),
            success=True,
        )
    except Exception as exc:
        logger.warning("Translation via %s failed: %s", provider, exc)
        return TranslationResult(error=str(exc))
