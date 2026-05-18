"""In-process provider cooldowns for the long-running worker.

PHP has its own provider circuit breaker, but the FastAPI worker performs
several direct provider calls. This module keeps those direct calls from
hammering a provider that is known to be out of quota or repeatedly failing.
"""
from __future__ import annotations

import logging
import os
import time
from typing import Any

logger = logging.getLogger(__name__)

_QUOTA_COOLDOWN_SECONDS = max(
    60,
    int(os.getenv("EPV2_PROVIDER_QUOTA_COOLDOWN_SECONDS", "3600") or "3600"),
)
_ERROR_COOLDOWN_SECONDS = max(
    30,
    int(os.getenv("EPV2_PROVIDER_ERROR_COOLDOWN_SECONDS", "300") or "300"),
)
_FAILURE_THRESHOLD = max(
    1,
    int(os.getenv("EPV2_PROVIDER_FAILURE_THRESHOLD", "3") or "3"),
)

_failures: dict[str, int] = {}
_cooldowns: dict[str, tuple[float, str]] = {}
_last_log: dict[str, float] = {}


def _now() -> float:
    return time.time()


def _text(error: Any) -> str:
    try:
        return str(error or "")
    except Exception:  # noqa: BLE001
        return ""


def classify_provider_error(error: Any) -> str:
    body = _text(error).lower()
    if (
        "insufficient_quota" in body
        or "exceeded your current quota" in body
        or "check your plan and billing" in body
        or "billing hard limit" in body
    ):
        return "quota"
    if "rate_limit" in body or "too many requests" in body or " 429" in body or "error code: 429" in body:
        return "rate_limit"
    return "error"


def provider_available(provider: str) -> bool:
    provider = (provider or "").strip().lower()
    if not provider:
        return True
    until, _reason = _cooldowns.get(provider, (0.0, ""))
    if until <= _now():
        _cooldowns.pop(provider, None)
        return True
    return False


def provider_unavailable_reason(provider: str) -> str:
    provider = (provider or "").strip().lower()
    until, reason = _cooldowns.get(provider, (0.0, ""))
    if until <= _now():
        return ""
    return f"{reason}; retry_after={int(until - _now())}s"


def register_provider_success(provider: str) -> None:
    provider = (provider or "").strip().lower()
    if not provider:
        return
    _failures.pop(provider, None)
    _cooldowns.pop(provider, None)


def register_provider_failure(provider: str, error: Any) -> str:
    provider = (provider or "").strip().lower()
    if not provider:
        return "error"
    kind = classify_provider_error(error)
    count = _failures.get(provider, 0) + 1
    _failures[provider] = count
    cooldown = 0
    if kind == "quota":
        cooldown = _QUOTA_COOLDOWN_SECONDS
    elif count >= _FAILURE_THRESHOLD:
        cooldown = _ERROR_COOLDOWN_SECONDS
    if cooldown > 0:
        _cooldowns[provider] = (_now() + cooldown, kind)
        _log_once(
            f"{provider}:{kind}",
            "%s provider cooldown active for %ss after %s failure: %s",
            provider,
            cooldown,
            kind,
            _text(error)[:240],
        )
    return kind


def _log_once(key: str, message: str, *args: object) -> None:
    now = _now()
    if now - _last_log.get(key, 0.0) < 60:
        return
    _last_log[key] = now
    logger.warning(message, *args)


def provider_health_snapshot() -> dict[str, Any]:
    now = _now()
    providers = sorted(set(_failures) | set(_cooldowns))
    snapshot: dict[str, Any] = {}
    for provider in providers:
        until, reason = _cooldowns.get(provider, (0.0, ""))
        retry_after = max(0, int(until - now))
        snapshot[provider] = {
            "available": retry_after <= 0,
            "failure_count": int(_failures.get(provider, 0)),
            "cooldown_reason": reason if retry_after > 0 else "",
            "retry_after_seconds": retry_after,
        }
    return snapshot
