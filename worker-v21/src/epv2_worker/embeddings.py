"""Semantic embeddings via OpenAI text-embedding-3-small.

Phase 1 (2026-05-12): compute embedding once per item during story_card
build. Returned alongside card; PHP stores в payload._meta.semantic_embedding
для future similarity-based dedup и related-content matching.

Model choice: text-embedding-3-small (1536 dim, multilingual, ~$0.02/1M tokens).
Что embed'им: title + excerpt + первые 500 символов content — компактный
семантический «отпечаток» истории, достаточный для сравнения, не раздутый.
"""

from __future__ import annotations

import json
import logging
from typing import Any

import httpx

from .provider_health import (
    provider_available,
    provider_unavailable_reason,
    register_provider_failure,
    register_provider_success,
)

logger = logging.getLogger(__name__)

EMBEDDING_MODEL = "text-embedding-3-small"
EMBEDDING_DIM = 1536
EMBEDDING_API_URL = "https://api.openai.com/v1/embeddings"
HTTP_TIMEOUT_SECONDS = 20


def _prepare_text_for_embedding(*, title: str, excerpt: str, content: str) -> str:
    """Compact text representation для embedding.

    Title + excerpt дают news-card смысл. First 500 chars content — углубление
    но не раздутие. Total ~600-800 tokens обычно.
    """
    title = (title or "").strip()
    excerpt = (excerpt or "").strip()
    content = (content or "").strip()
    parts: list[str] = []
    if title:
        parts.append(title)
    if excerpt:
        parts.append(excerpt)
    if content:
        # Drop trivial overlap if excerpt уже start of content
        snippet = content[:500].strip()
        if snippet and (not excerpt or not snippet.startswith(excerpt[:80])):
            parts.append(snippet)
    return "\n\n".join(parts).strip()


async def compute_embedding(
    *,
    title: str,
    excerpt: str,
    content: str,
    openai_api_key: str,
) -> dict[str, Any]:
    """Compute embedding для item; returns dict or empty dict on failure.

    Return shape: {
        "model": "text-embedding-3-small",
        "dim": 1536,
        "vector": [...1536 floats...],
        "input_chars": int,
    }
    or {} если key missing / API error — caller treats absence как «no embedding».
    Никогда не raises — graceful pipeline degradation.
    """
    if not openai_api_key:
        return {}
    if not provider_available("openai"):
        logger.warning("embedding skipped: openai cooldown %s", provider_unavailable_reason("openai"))
        return {}
    text = _prepare_text_for_embedding(title=title, excerpt=excerpt, content=content)
    if not text:
        return {}
    try:
        async with httpx.AsyncClient(timeout=HTTP_TIMEOUT_SECONDS) as client:
            response = await client.post(
                EMBEDDING_API_URL,
                headers={
                    "Authorization": f"Bearer {openai_api_key}",
                    "Content-Type": "application/json",
                },
                json={
                    "model": EMBEDDING_MODEL,
                    "input": text,
                    "encoding_format": "float",
                },
            )
            response.raise_for_status()
            data = response.json()
    except httpx.HTTPStatusError as exc:
        body = exc.response.text if exc.response is not None else ""
        register_provider_failure("openai", body or exc)
        logger.warning(
            "embedding HTTP error model=%s status=%s body=%s",
            EMBEDDING_MODEL,
            getattr(exc.response, "status_code", "?"),
            body[:200],
        )
        return {}
    except Exception as exc:  # noqa: BLE001
        register_provider_failure("openai", exc)
        logger.warning("embedding error model=%s err=%s", EMBEDDING_MODEL, exc)
        return {}
    items = data.get("data") or []
    if not items:
        return {}
    vector = items[0].get("embedding") or []
    if not isinstance(vector, list) or len(vector) != EMBEDDING_DIM:
        logger.warning(
            "embedding unexpected shape model=%s got_len=%s",
            EMBEDDING_MODEL,
            len(vector) if isinstance(vector, list) else "non-list",
        )
        return {}
    usage = data.get("usage") or {}
    register_provider_success("openai")
    return {
        "model": EMBEDDING_MODEL,
        "dim": EMBEDDING_DIM,
        "vector": vector,
        "input_chars": len(text),
        "tokens": int(usage.get("total_tokens", 0) or 0),
    }
