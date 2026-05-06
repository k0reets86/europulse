"""
EuroPulse AutoPilot v2.1 — Worker HTTP Server
Persistent FastAPI service on 127.0.0.1:8765.
"""
from __future__ import annotations

import os
import logging
from typing import Any

from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from .pipeline import run_pipeline, build_normalized_payload
from .contracts import WorkerRequest
from .story_card import build_story_card

logger = logging.getLogger(__name__)
app = FastAPI(title="EPV2 Worker", version="2.1")


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
    return {"status": "ok", "version": "2.1"}


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
    openai_model: str = "gpt-5-mini"
    worker_token: str = ""


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
        card = await build_story_card(
            title=req.title,
            excerpt=req.excerpt,
            body=req.content,
            url=req.url,
            source_name=req.source_name,
            language_hint=req.language_hint,
            category_bias=req.category_bias,
            openai_api_key=req.openai_api_key,
            deepseek_api_key=req.deepseek_api_key,
            openai_model=req.openai_model or "gpt-5-mini",
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("Story card error for queue_id=%s", req.queue_id)
        raise HTTPException(status_code=500, detail=str(exc)) from exc
    return JSONResponse(content={"queue_id": req.queue_id, "card": card.to_dict()})


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
        response["payload"] = build_normalized_payload(result)
        return JSONResponse(content=response)
    except Exception as exc:
        logger.exception("Pipeline error for item_id=%s", req.queue_id or req.item_id)
        raise HTTPException(status_code=500, detail=str(exc)) from exc
