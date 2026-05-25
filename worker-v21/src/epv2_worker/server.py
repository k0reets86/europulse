"""
EuroPulse AutoPilot v2.1 — Worker HTTP Server
Persistent FastAPI service on 127.0.0.1:8765.
"""
from __future__ import annotations

import os
import logging
import threading
import time
from typing import Any

from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from .pipeline import run_pipeline, build_normalized_payload
from .contracts import WorkerRequest
from .story_card import build_story_card
from .embeddings import compute_embedding
from .provider_health import provider_health_snapshot

logger = logging.getLogger(__name__)
app = FastAPI(title="EPV2 Worker", version="2.1")

RECYCLE_RSS_MB = max(128, int(os.getenv("EPV2_WORKER_RECYCLE_RSS_MB", "512") or "512"))
UNHEALTHY_RSS_MB = max(128, int(os.getenv("EPV2_WORKER_UNHEALTHY_RSS_MB", "600") or "600"))
RECYCLE_AFTER_REQUESTS = max(0, int(os.getenv("EPV2_WORKER_RECYCLE_AFTER_REQUESTS", "0") or "0"))
RSS_MONITOR_INTERVAL_SECONDS = max(1, int(os.getenv("EPV2_WORKER_RSS_MONITOR_INTERVAL_SECONDS", "3") or "3"))
_request_count = 0
_recycle_scheduled = False
_monitor_started = False
_recycle_lock = threading.Lock()


def _rss_mb() -> float:
    try:
        with open("/proc/self/status", "r", encoding="utf-8") as handle:
            for line in handle:
                if line.startswith("VmRSS:"):
                    parts = line.split()
                    if len(parts) >= 2:
                        return int(parts[1]) / 1024.0
    except (OSError, ValueError):
        return 0.0
    return 0.0


def _schedule_recycle(reason: str, rss_mb: float) -> None:
    global _recycle_scheduled
    with _recycle_lock:
        if _recycle_scheduled:
            return
        _recycle_scheduled = True
    logger.warning("Worker recycle scheduled: reason=%s rss_mb=%.1f", reason, rss_mb)

    def _exit() -> None:
        os._exit(75)

    threading.Timer(0.5, _exit).start()


def _rss_monitor_loop() -> None:
    while True:
        time.sleep(RSS_MONITOR_INTERVAL_SECONDS)
        rss_mb = _rss_mb()
        if rss_mb >= RECYCLE_RSS_MB:
            _schedule_recycle("rss_monitor_limit", rss_mb)
            return


@app.on_event("startup")
async def start_resource_monitor() -> None:
    global _monitor_started
    if _monitor_started:
        return
    _monitor_started = True
    threading.Thread(target=_rss_monitor_loop, name="epv2_worker_rss_monitor", daemon=True).start()


@app.middleware("http")
async def resource_recycle_middleware(request: Request, call_next):
    global _request_count
    response = await call_next(request)
    if request.url.path in {"/process", "/analyze_story"}:
        _request_count += 1
    rss_mb = _rss_mb()
    if rss_mb >= RECYCLE_RSS_MB:
        _schedule_recycle("rss_limit", rss_mb)
    elif RECYCLE_AFTER_REQUESTS and _request_count >= RECYCLE_AFTER_REQUESTS:
        _schedule_recycle("request_limit", rss_mb)
    return response


class ProcessRequest(BaseModel):
    item_id: int = 0
    queue_id: int = 0
    stage: str = "full_bundle"
    original_url: str = ""
    original_title: str = ""
    original_excerpt: str = ""
    original_content: str = ""
    original_date: str = ""
    source_image_url: str = ""
    category_proposed: str = ""
    category_final: str = ""
    source_language: str = ""
    story_kind: str = "news"
    story_format: str = "news"
    length_profile: str = "standard"
    editorial_flags: dict[str, Any] = {}
    context_memory: dict[str, Any] = {}
    openai_api_key: str = ""
    deepseek_api_key: str = ""
    gemini_api_key: str = ""
    pexels_api_key: str = ""
    # For per-block regeneration
    regen_block: str = ""     # "title"|"lead"|"body"|"media"|"seo"|"" (full)
    existing_payload: dict[str, Any] = {}
    worker_token: str = ""


@app.get("/health")
async def health() -> dict:
    rss_mb = _rss_mb()
    payload = {
        "status": "ok",
        "version": "2.1",
        "providers": provider_health_snapshot(),
        "rss_mb": round(rss_mb, 1),
        "request_count": _request_count,
        "recycle_scheduled": _recycle_scheduled,
    }
    if rss_mb >= UNHEALTHY_RSS_MB or _recycle_scheduled:
        payload["status"] = "recycling"
        return JSONResponse(status_code=503, content=payload)
    return payload


class AnalyzeStoryRequest(BaseModel):
    queue_id: int = 0
    title: str = ""
    excerpt: str = ""
    content: str = ""
    url: str = ""
    source_name: str = ""
    language_hint: str = ""
    category_bias: str = ""
    openai_api_key: str = ""
    deepseek_api_key: str = ""
    ai_provider: str = ""
    ai_model: str = ""
    ai_fallback_provider: str = ""
    ai_fallback_model: str = ""
    openai_model: str = "gpt-4o-mini"
    worker_token: str = ""


def _story_provider_order(req: AnalyzeStoryRequest) -> tuple[str, ...]:
    keys = {
        "openai": bool((req.openai_api_key or "").strip()),
        "deepseek": bool((req.deepseek_api_key or "").strip()),
    }
    primary_raw = (req.ai_provider or "").strip().lower()
    fallback_raw = (req.ai_fallback_provider or "").strip().lower()
    primary = primary_raw or "openai"
    fallback = fallback_raw
    order: list[str] = []
    if primary in keys and keys[primary]:
        order.append(primary)
    if fallback in keys and keys[fallback] and fallback not in order:
        order.append(fallback)
    if order and (primary_raw or fallback_raw):
        return tuple(order)
    return tuple(provider for provider in ("openai", "deepseek") if keys[provider])


async def _empty_embedding() -> dict[str, Any]:
    return {}


@app.post("/analyze_story")
async def analyze_story(
    req: AnalyzeStoryRequest, x_epv2_worker_token: str = Header(default="")
) -> JSONResponse:
    """Single upfront semantic pass that builds a structured story card.

    PHP processor calls this once per fresh queue row before the rewriter
    runs. The card is stored in `ai_payload._meta.story_card` and consumed
    by every later stage (categorizer override, media resolver, rewriter
    structure hints, SEO, tagger).
    """
    expected_token = os.getenv("EPV2_WORKER_TOKEN", "").strip()
    provided_token = (x_epv2_worker_token or req.worker_token or "").strip()
    if expected_token and provided_token != expected_token:
        raise HTTPException(status_code=403, detail="Invalid worker token")
    try:
        # Phase 1 (2026-05-12): compute story_card и semantic embedding в
        # параллель. Embedding отдельный OpenAI вызов (text-embedding-3-small),
        # latency ~200-500ms — параллельно не замедляет card pass. Embedding
        # пишется в payload._meta.semantic_embedding на PHP side для future
        # similarity-based dedup. Phase 1 = generate + store only (no usage).
        import asyncio
        provider_order = _story_provider_order(req)
        card_task = build_story_card(
            title=req.title,
            excerpt=req.excerpt,
            body=req.content,
            url=req.url,
            source_name=req.source_name,
            language_hint=req.language_hint,
            category_bias=req.category_bias,
            openai_api_key=req.openai_api_key,
            deepseek_api_key=req.deepseek_api_key,
            provider_order=provider_order,
            openai_model=req.openai_model or "gpt-4o-mini",
        )
        emb_task = (
            compute_embedding(
                title=req.title,
                excerpt=req.excerpt,
                content=req.content,
                openai_api_key=req.openai_api_key,
            )
            if "openai" in provider_order
            else _empty_embedding()
        )
        card, embedding = await asyncio.gather(card_task, emb_task)
    except Exception as exc:  # noqa: BLE001
        logger.exception("Story card error for queue_id=%s", req.queue_id)
        raise HTTPException(status_code=500, detail=str(exc)) from exc
    response_body = {"queue_id": req.queue_id, "card": card.to_dict()}
    if embedding:
        response_body["embedding"] = embedding
    return JSONResponse(content=response_body)


@app.post("/process")
async def process(req: ProcessRequest, x_epv2_worker_token: str = Header(default="")) -> JSONResponse:
    try:
        expected_token = os.getenv("EPV2_WORKER_TOKEN", "").strip()
        provided_token = (x_epv2_worker_token or req.worker_token or "").strip()
        if expected_token and provided_token != expected_token:
            raise HTTPException(status_code=403, detail="Invalid worker token")
        queue_id = req.queue_id or req.item_id
        stage = req.stage or (req.regen_block if req.regen_block else "full_bundle")
        editorial_flags = dict(req.editorial_flags or {})
        if not editorial_flags:
            editorial_flags = {
                "openai_api_key": req.openai_api_key,
                "deepseek_api_key": req.deepseek_api_key,
                "gemini_api_key": req.gemini_api_key,
                "pexels_api_key": req.pexels_api_key,
            }
        worker_req = WorkerRequest(
            queue_id=queue_id,
            stage=stage,
            story_kind=req.story_kind,
            length_profile=req.length_profile,
            original_title=req.original_title,
            original_excerpt=req.original_excerpt,
            original_content=req.original_content,
            original_url=req.original_url,
            original_date=req.original_date,
            source_image_url=req.source_image_url,
            source_language=req.source_language,
            category_proposed=req.category_proposed,
            category_final=req.category_final,
            story_format=req.story_format,
            editorial_flags=editorial_flags,
            existing_payload=req.existing_payload,
        )
        result = await run_pipeline(worker_req)
        response = result.to_dict()
        normalized = build_normalized_payload(result)
        # Propagate content_kind from the request payload back to the
        # normalized response so PHP-side EPV2_Content_Kinds::detect_kind
        # hits its cache on subsequent gate evaluations and the kind
        # does not silently drift between worker round-trips.
        existing_meta = (req.existing_payload or {}).get("_meta") if isinstance(req.existing_payload, dict) else {}
        propagated_kind = ""
        if isinstance(existing_meta, dict):
            propagated_kind = str(existing_meta.get("content_kind") or "")
        if propagated_kind and isinstance(normalized.get("_meta"), dict):
            normalized["_meta"]["content_kind"] = propagated_kind
        response["payload"] = normalized
        return JSONResponse(content=response)
    except Exception as exc:
        logger.exception("Pipeline error for item_id=%s", req.queue_id or req.item_id)
        raise HTTPException(status_code=500, detail=str(exc)) from exc
