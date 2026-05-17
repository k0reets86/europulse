"""Composable prompt modules for EuroPulse rewriter.

Architecture (per docs/architecture-deep-audit.md section 4):
    final_prompt = base_voice
                 + type_module(kind)
                 + rubric_module(rubric)
                 + story_card_block
                 + dossier_block

This package owns all *editorial* prompt content; mechanical scaffolding
(JSON output schema, retry logic, API call) stays in rewriter.py.
"""

# 2026-05-13: Single source of truth for prompt version. Bump when ANY
# editorial prompt (base_voice/types/rubrics/compose) меняется. PHP
# `EPV2_AI_Processor::EDITORIAL_PROMPT_VERSION` сравнивает с этим значением
# и дропает stale payloads на resume.
PROMPT_VERSION = "2026-05-16-v23"

from .base_voice import BASE_VOICE
from .types import TYPE_MODULES
from .rubrics import RUBRIC_MODULES
from .compose import compose_rewrite_prompt

__all__ = [
    "PROMPT_VERSION",
    "BASE_VOICE",
    "TYPE_MODULES",
    "RUBRIC_MODULES",
    "compose_rewrite_prompt",
]
