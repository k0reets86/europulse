"""
Compatibility helpers for OpenAI reasoning models on the worker's pinned SDK.

The live worker currently uses an older openai package. Newer Chat Completions
fields must be forwarded through extra_body, otherwise the SDK rejects them.
"""
from __future__ import annotations

from typing import Any


def is_reasoning_chat_model(model: str) -> bool:
    model = (model or "").strip()
    return model.startswith(("gpt-5", "o"))


def reasoning_extra_body(model: str, max_completion_tokens: int) -> dict[str, Any]:
    extra: dict[str, Any] = {
        "max_completion_tokens": max(256, int(max_completion_tokens)),
    }
    if is_reasoning_chat_model(model):
        # Keep low-latency editorial JSON tasks from spending the whole budget
        # on hidden reasoning tokens and returning an empty visible message.
        extra["reasoning_effort"] = "minimal"
    return extra


def completion_total_tokens(response: Any) -> int:
    """Return total tokens reported by the OpenAI/DeepSeek response, or 0."""
    usage = getattr(response, "usage", None)
    if usage is None:
        return 0
    total = getattr(usage, "total_tokens", None)
    if total is None:
        # Some providers omit total_tokens — fall back to prompt+completion.
        prompt = getattr(usage, "prompt_tokens", 0) or 0
        completion = getattr(usage, "completion_tokens", 0) or 0
        total = (prompt or 0) + (completion or 0)
    try:
        return max(0, int(total))
    except (TypeError, ValueError):
        return 0


def completion_text(response: Any) -> str:
    try:
        content = response.choices[0].message.content
    except Exception:
        return ""
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        parts: list[str] = []
        for item in content:
            if isinstance(item, dict):
                parts.append(str(item.get("text") or item.get("content") or ""))
            else:
                parts.append(str(getattr(item, "text", "") or getattr(item, "content", "") or ""))
        return "".join(parts)
    return str(content or "")


def completion_debug(response: Any) -> str:
    try:
        choice = response.choices[0]
    except Exception:
        return "choice=missing"

    finish_reason = str(getattr(choice, "finish_reason", "") or "")
    usage = getattr(response, "usage", None)
    usage_bits: list[str] = []
    for attr in ("prompt_tokens", "completion_tokens", "total_tokens"):
        value = getattr(usage, attr, None) if usage is not None else None
        if value is not None:
            usage_bits.append(f"{attr}={value}")
    details = getattr(usage, "completion_tokens_details", None) if usage is not None else None
    reasoning_tokens = getattr(details, "reasoning_tokens", None) if details is not None else None
    if reasoning_tokens is not None:
        usage_bits.append(f"reasoning_tokens={reasoning_tokens}")
    usage_text = " ".join(usage_bits) if usage_bits else "usage=missing"
    return f"finish_reason={finish_reason or 'unknown'} {usage_text}"
