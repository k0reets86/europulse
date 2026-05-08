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

from .base_voice import BASE_VOICE
from .types import TYPE_MODULES
from .rubrics import RUBRIC_MODULES
from .compose import compose_rewrite_prompt

__all__ = [
    "BASE_VOICE",
    "TYPE_MODULES",
    "RUBRIC_MODULES",
    "compose_rewrite_prompt",
]
