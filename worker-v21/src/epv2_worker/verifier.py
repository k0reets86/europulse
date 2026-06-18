"""Groundedness verify-and-CORRECT pass (2026-06-17).

Audit of 76 articles showed ~36-40% contained fabrications (invented casualties,
officials, quotes, numbers, events) that the rewrite prompt + regex validators
could not stop — the model fabricates on top of a valid source.

This is one extra LLM call AFTER the German rewrite and BEFORE translation. It
compares the article to the source and REMOVES/CORRECTS any fact not supported
by the source, then returns the corrected article. We CORRECT and publish — we
do NOT block. Runs on the cheap primary provider (deepseek by default).

Correcting the German master before translation means the fix propagates to
UK/EN automatically.
"""

from __future__ import annotations

import json
import logging

from openai import AsyncOpenAI

logger = logging.getLogger(__name__)

_VERIFY_SYSTEM = """Du bist ein strenger Faktenprüfer und Korrektor einer Nachrichtenredaktion.
Du bekommst eine QUELLE (Originaltext) und einen ARTIKEL (generierter deutscher Text).

AUFGABE: Prüfe JEDEN Fakt im ARTIKEL gegen die QUELLE und KORRIGIERE den Artikel.
Geprüft werden: Namen + Funktionen/Ämter, Zahlen, OPFERZAHLEN (Tote/Verletzte/Kinder —
auch ein- und zweistellige), Daten, Wochentage, Uhrzeiten, wörtliche Zitate, Orte,
Ereignisse, Ursachen, Quellennennungen.

REGELN FÜR DIE KORREKTUR:
- Entferne jede Aussage, die NICHT durch die QUELLE gedeckt ist (erfundene Opferzahlen,
  erfundene Sprecher/Beamte/Analysten, erfundene Zitate, erfundene Zahlen/Distanzen,
  erfundene Ereignisse/Treffen/Telefonate, erfundene Orte).
- Korrigiere jede Aussage, die der QUELLE WIDERSPRICHT, auf den Wert der QUELLE
  (z. B. „backbord" vs „steuerbord", „3 Verletzte" vs „15 Verletzte", falscher Wochentag).
- Größenordnung exakt: „60 Millionen" darf nicht „60" werden.
- KEINE neuen Fakten hinzufügen, die nicht in der QUELLE stehen.
- Erfundene Wochentage entfernen, wenn die QUELLE nur ein Datum nennt.
- Wenn nach dem Entfernen ein Absatz zu dünn ist: lieber kürzer und korrekt.
- Behalte flüssiges, redaktionelles Deutsch und die Struktur (title, lead, card_lead, body).
- Ist bereits ALLES gedeckt: gib den Artikel unverändert zurück.

Antworte AUSSCHLIESSLICH mit einem JSON-Objekt:
{"title": "...", "lead": "...", "card_lead": "...", "body": "...", "corrections": ["kurze Liste der entfernten/korrigierten Fakten"]}"""


async def verify_and_correct_german(
    *,
    title: str,
    lead: str,
    card_lead: str,
    body: str,
    source_text: str,
    provider: str,
    api_key: str,
    model: str,
    max_tokens: int = 3000,
) -> dict | None:
    """Returns corrected {title, lead, card_lead, body, corrections:[...]} or None on failure.

    On ANY error returns None so the caller keeps the original article (graceful —
    we never lose an article because the verifier hiccuped)."""
    src = (source_text or "").strip()
    if not src or not (body or "").strip():
        return None
    # Если источник короче ~200 симв — нечего сверять надёжно, пропускаем.
    if len(src) < 200:
        return None
    try:
        if provider == "deepseek":
            client = AsyncOpenAI(api_key=api_key, base_url="https://api.deepseek.com/v1")
        else:
            client = AsyncOpenAI(api_key=api_key)
        article = json.dumps(
            {"title": title, "lead": lead, "card_lead": card_lead, "body": body},
            ensure_ascii=False,
        )
        user = (
            "QUELLE (Originaltext, einzige Wahrheitsgrundlage):\n"
            + src[:9000]
            + "\n\n----------\nARTIKEL (zu prüfen und zu korrigieren):\n"
            + article
            + "\n\nGib den geprüften, korrigierten ARTIKEL als JSON zurück."
        )
        resp = await client.chat.completions.create(
            model=model,
            messages=[
                {"role": "system", "content": _VERIFY_SYSTEM},
                {"role": "user", "content": user},
            ],
            response_format={"type": "json_object"},
            temperature=0.0,
            max_tokens=max_tokens,
        )
        raw = (resp.choices[0].message.content or "").strip()
        if not raw:
            return None
        # strict=False допускает сырые control-символы (переносы строк) внутри
        # строковых значений — модель иногда кладёт их в body, и обычный
        # json.loads падал «Invalid control character», теряя исправление.
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            data = json.loads(raw, strict=False)
        out = {
            "title": str(data.get("title", "") or "").strip(),
            "lead": str(data.get("lead", "") or "").strip(),
            "card_lead": str(data.get("card_lead", "") or "").strip(),
            "body": str(data.get("body", "") or "").strip(),
            "corrections": [str(c) for c in (data.get("corrections") or []) if str(c).strip()][:20],
        }
        # Защита ТОЛЬКО от пустого вывода. Сильное сжатие — это НОРМА, когда
        # статья была в основном выдумкой (корректная версия законно короче).
        # Раньше относительный порог 35% backfire'ил: блокировал корректировку
        # как раз там, где она нужнее всего. Оставляем абсолютный минимум.
        if not out["title"] or len(out["body"]) < 80:
            logger.warning("verify_and_correct: corrected output empty/near-empty, keeping original")
            return None
        return out
    except Exception as exc:  # noqa: BLE001
        logger.warning("verify_and_correct failed: %s", exc)
        return None
