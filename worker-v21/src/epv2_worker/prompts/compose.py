"""Compose the final rewriter prompt from base voice + type + rubric + facts."""

from __future__ import annotations

from .base_voice import BASE_VOICE
from .types import type_module
from .rubrics import rubric_module


def compose_rewrite_prompt(
    *,
    kind: str,
    rubric_slug: str,
    story_card_block: str,
    dossier_block: str,
    original_title: str,
    original_content: str,
    source_url: str,
    source_language: str,
    publication_name: str = "",
) -> str:
    """Assemble the layered prompt for the rewriter.

    Args:
        kind: KIND_SPECS slug (news_article, news_brief, breaking_alert, ...).
        rubric_slug: WP category slug (politik, wirtschaft, sport, ...).
        story_card_block: Pre-formatted story card facts block (from rewriter._format_story_card_block).
        dossier_block: Pre-formatted dossier block with primary + related sources.
        original_title: Original headline from source.
        original_content: Original body text from source.
        source_url: URL of the primary source.
        source_language: ISO code of the source language ("de", "uk", "en", "fr", ...).
        publication_name: Display name of the source publication, used for in-lead attribution.

    Returns:
        Final string to send as user prompt.
    """
    type_block = type_module(kind)
    rubric_block = rubric_module(rubric_slug)

    attribution_hint = (
        f"Primärquelle für die Lead-Attribution: {publication_name} ({source_url})."
        if publication_name and source_url
        else (f"Primärquelle: {source_url}" if source_url else "")
    )

    sections = [
        BASE_VOICE,
        type_block,
        rubric_block,
        attribution_hint,
        story_card_block,
        dossier_block,
        "ORIGINAL TITEL:\n" + (original_title or "").strip(),
        "ORIGINAL INHALT (Quellsprache: " + source_language + "):\n" + (original_content or "").strip()[:6000],
        (
            "AUFGABE: Erstelle einen deutschen Artikel gemäß ALLEN Regeln oben.\n"
            "Antworte NUR mit dem JSON-Objekt — keine Markdown-Codefences, kein Vorwort, kein Nachwort.\n"
            "ZUSÄTZLICHES PFLICHTFELD card_lead: GENAU EIN vollständiger geschlossener Satz mit 110–130 Zeichen, "
            "geschrieben als Karten-Lead-Magnet für die Startseite. Er muss eigenständig Sinn ergeben und Lust auf den Artikel machen. "
            "KEINE Abkürzungen mit Punkt im Inneren (\"8. Mai\", \"z. B.\", \"St. Petersburg\" verboten — bitte ausschreiben oder umformulieren). "
            "KEINE drei Punkte am Ende, KEINE offenen Sätze, KEIN Ende auf Präposition / Konjunktion / Artikel / Hilfsverb. "
            "Der Satz endet mit einem klassischen Punkt, Frage- oder Ausrufezeichen. NICHT identisch zum lead, NICHT identisch zum title. "
            "KEINE QUELLENANGABE im card_lead — verboten sind \"Wie X berichtet\", \"nach Angaben von X\", \"X zufolge\", \"laut X\", \"X mitteilt\". "
            "Die Quelle gehört in den Body, nicht in den Karten-Hook. Der card_lead muss die Nachricht selbst tragen, nicht die Tatsache der Meldung.\n"
            'Schema: {"title": "...", "lead": "1–2 Sätze Teaser", "card_lead": "ein geschlossener Satz, 110–130 Zeichen", "body": "vollständiger Artikel"}'
        ),
    ]

    return "\n\n".join(s.strip() for s in sections if s and s.strip())
