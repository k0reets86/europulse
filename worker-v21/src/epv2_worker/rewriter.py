"""
EuroPulse AutoPilot v2.1 — DE Master Rewriter
Rewrites article into German using OpenAI GPT-4o Mini (primary) or DeepSeek (fallback).
"""
from __future__ import annotations

import logging
import re
from dataclasses import dataclass
from urllib.parse import urlparse

from openai import AsyncOpenAI

from .openai_compat import completion_debug, completion_text, reasoning_extra_body

logger = logging.getLogger(__name__)

# Map of known publication hosts → display name used in attribution phrases
_SOURCE_NAME_MAP: dict[str, str] = {
    "tagesschau.de": "Tagesschau",
    "ard.de": "ARD",
    "zdf.de": "ZDF",
    "spiegel.de": "Der Spiegel",
    "zeit.de": "Die Zeit",
    "faz.net": "FAZ",
    "sueddeutsche.de": "Süddeutsche Zeitung",
    "welt.de": "Die Welt",
    "focus.de": "Focus",
    "stern.de": "Stern",
    "bild.de": "Bild",
    "handelsblatt.com": "Handelsblatt",
    "tagesspiegel.de": "Der Tagesspiegel",
    "dw.com": "Deutsche Welle",
    "reuters.com": "Reuters",
    "apnews.com": "AP",
    "bbc.com": "BBC",
    "bbc.co.uk": "BBC",
    "theguardian.com": "The Guardian",
    "nytimes.com": "New York Times",
    "bloomberg.com": "Bloomberg",
    "ft.com": "Financial Times",
    "euronews.com": "Euronews",
    "politico.eu": "Politico Europe",
    "economist.com": "The Economist",
    "derstandard.at": "Der Standard",
    "orf.at": "ORF",
    "nzz.ch": "NZZ",
    "20min.ch": "20 Minuten",
    "lemonde.fr": "Le Monde",
    "lefigaro.fr": "Le Figaro",
    "elpais.com": "El País",
    "corriere.it": "Corriere della Sera",
    "pravda.com.ua": "Ukrainska Pravda",
    "ukrinform.ua": "Ukrinform",
    "liga.net": "Liga.net",
    "rbc.ua": "RBC Ukraine",
    "unian.ua": "UNIAN",
}


def source_name_from_url(url: str) -> str:
    """Extract a human-readable publication name from a URL."""
    if not url:
        return ""
    try:
        host = urlparse(url).netloc.lower().lstrip("www.")
        if host in _SOURCE_NAME_MAP:
            return _SOURCE_NAME_MAP[host]
        # Check for partial matches (e.g. subdomain.tagesschau.de)
        for key, name in _SOURCE_NAME_MAP.items():
            if host.endswith("." + key) or host == key:
                return name
        # Fallback: capitalize first label of the domain
        label = host.split(".")[0]
        return label.capitalize() if label else ""
    except Exception:
        return ""


@dataclass
class RewriteResult:
    title_de: str = ""
    lead_de: str = ""
    body_de: str = ""
    success: bool = False
    error: str = ""
    provider: str = ""
    model: str = ""


_SYSTEM_PROMPT = """Du bist ein professioneller deutschsprachiger Nachrichtenredakteur für EuroPulse.today —
ein qualitätsorientiertes Multilingual-Nachrichtenportal (DE/UK/EN) für die ukrainische
Diaspora und das deutschsprachige Publikum, optimiert für Google News, Discover und
moderne Antwort-Suchmaschinen (Perplexity, ChatGPT, Claude).

REDAKTIONS-LEITLINIE (E-E-A-T):
- Sachliche, neutrale Hochdeutsch-Texte. Keine Meinungen, kein Clickbait, keine Reklame.
- Inverse Pyramide: Wer/Was/Wann/Wo (und falls bekannt Warum) in den ersten 1–2 Sätzen.
- Kurze, klare Sätze. Aktiv vor Passiv, sofern kein Akteur im Original benannt wird.
- Sprache wirkt menschlich-redaktionell, nicht KI-typisch: keine Bindestrich-Manie
  („—" sparsam), keine Phrasen wie „im digitalen Zeitalter", „in der heutigen Welt",
  „bietet zahlreiche Vorteile". Keine Listicles, keine rhetorischen Fragen als Lead.

QUELLENANGABEN (Pflicht):
- Im LEAD oder spätestens im ersten Body-Absatz die Primärquelle explizit nennen, mit
  lebendiger Formel: „Wie [Quelle] berichtet, …", „Nach Angaben von [Quelle] …",
  „[Quelle] zufolge …", „Wie [Quelle] mitteilt, …".
- Bei wörtlichen Zitaten Vollform: „[Person], [Funktion], erklärte gegenüber [Quelle]: ‚…'".
- Erstes Auftreten einer Abkürzung oder eines Fachbegriffs ausschreiben, Kurzform in
  Klammern, z. B. „Europäische Union (EU)".
- Keine anonymen Behauptungen ohne Quellzuordnung. Wenn keine Quelle bekannt ist:
  „aus Behördenkreisen", „nach offiziellen Angaben" — niemals freihändig zitieren.

FAKTENREGELN (gegen Halluzinationen):
- Tiefer faktischer Rewrite — kein Nacherzählen, keine Erfindungen.
- Alle harten Fakten (Datum, Uhrzeit, Ort, Zahlen, Namen, Funktionen, Zitate, Kausalitäten)
  stammen ausschließlich aus dem Original, der Story-Card oder den Schlüsselbegriffen.
- Relative Zeitangaben („gestern", „morgen", „am Abend") nicht in konkrete Kalenderdaten
  umrechnen, außer das Original nennt ein genaues Datum.
- Keine erfundenen Jahreszahlen, Hintergründe, Organisationen, Teilnehmer, Zitate,
  Motive oder Folgen. Keine spekulativen Konsequenzen („Das könnte bedeuten, dass …").
- Keine Vornamen ergänzen, wenn die Quelle nur Nachnamen nennt. Bei „Miersch" bleibt es
  „Miersch" oder „SPD-Fraktionschef Miersch"; nicht „Johannes/Matthias Miersch".
- Fehlende Details: allgemeiner formulieren oder weglassen — kein Fülltext.
- Wenn die Fakten für die Ziel-Länge nicht reichen: kürzer und präziser schreiben statt
  aufzublähen. Lieber 200 saubere Wörter als 500 mit Wiederholungen.

STRUKTUR DES BODY:
- Kurze Absätze (40–90 Wörter). Maschinen lesen Absatzanfänge zuerst.
- Wenn der Stoff es trägt: 2–3 H2-Zwischenüberschriften zur Gliederung längerer Texte
  (Format: <h2>Untertitel</h2>). Eine H2 reicht aber nie als reines Keyword-Stuffing —
  sie soll inhaltlich den nächsten Absatz beschreiben.
- Konkrete Zahlen und Eigennamen früh und mehrfach im Text wiederholen — gut für
  Such-Indexierung und Entitäts-Erkennung.
- Letzten Absatz für Einordnung / Kontext / Folgen, falls die Quelle das hergibt.

OUTPUT-FORMAT:
Ausgabe ausschließlich als gültiges JSON mit den Feldern: title, lead, body.
- title: 50–80 Zeichen, faktisch, kein Clickbait, Hauptkeyword möglichst weit vorn.
- lead: 1–2 Sätze, beantwortet Wer/Was/Wann/Wo, ohne Wiederholung des Titels.
- body: HTML-Absätze (<p>...</p>) plus optionale <h2>Zwischenüberschrift</h2>;
  keine Markdown-Sterne, keine Listen außer wenn die Quelle eine echte Liste enthält."""


def _format_story_card_block(card: dict | None) -> str:
    """Format the upfront story card as a German prompt section.

    The card is the result of EPV2_Story_Card_Builder (one upfront AI call
    per item) and gives the rewriter a verifiable list of entities and key
    facts to anchor on. It does NOT replace the source text — the
    rewriter still works from `original_content` — but it lets the model
    cross-check facts and avoid hallucinating. Returns "" when no card.
    """
    if not isinstance(card, dict) or not card:
        return ""
    parts: list[str] = []
    cat = (card.get("category") or {})
    primary_cat = str(cat.get("primary") or "").strip()
    if primary_cat:
        rationale = str(cat.get("rationale") or "").strip()
        parts.append(f"Kategorie: {primary_cat}" + (f" — {rationale}" if rationale else ""))
    geo = card.get("geography") or {}
    if isinstance(geo, dict):
        country = str(geo.get("primary_country") or "").strip()
        region = str(geo.get("primary_region") or "").strip()
        if country or region:
            geo_line = "Geographie: "
            geo_line += country
            if region:
                geo_line += f" / {region}" if country else region
            parts.append(geo_line.strip())
    people = card.get("entities_people") or card.get("entities", {}).get("people") or []
    if isinstance(people, list) and people:
        formatted = []
        for p in people[:6]:
            if not isinstance(p, dict):
                continue
            name = str(p.get("name") or "").strip()
            role = str(p.get("role") or "").strip()
            if name and role:
                formatted.append(f"{name} ({role})")
            elif name:
                formatted.append(name)
        if formatted:
            parts.append("Personen: " + ", ".join(formatted))
    orgs = card.get("entities_organizations") or card.get("entities", {}).get("organizations") or []
    if isinstance(orgs, list) and orgs:
        formatted = []
        for o in orgs[:5]:
            if not isinstance(o, dict):
                continue
            name = str(o.get("name") or "").strip()
            if name:
                formatted.append(name)
        if formatted:
            parts.append("Organisationen: " + ", ".join(formatted))
    places = card.get("entities_places") or card.get("entities", {}).get("places") or []
    if isinstance(places, list) and places:
        formatted = [str(p).strip() for p in places[:5] if str(p).strip()]
        if formatted:
            parts.append("Orte: " + ", ".join(formatted))
    facts = card.get("key_facts") or []
    if isinstance(facts, list) and facts:
        bullet_lines = "\n".join(f"  • {str(f).strip()}" for f in facts[:6] if str(f).strip())
        if bullet_lines:
            parts.append("Schlüssel-Fakten (nur diese verwenden, keine Ergänzungen):\n" + bullet_lines)
    rewrite_hints = card.get("rewrite") or {}
    if isinstance(rewrite_hints, dict):
        hint_bits = []
        tone = str(rewrite_hints.get("tone") or "").strip()
        if tone:
            hint_bits.append(f"Ton={tone}")
        structure = str(rewrite_hints.get("structure") or "").strip()
        if structure:
            hint_bits.append(f"Struktur={structure}")
        length_profile_hint = str(rewrite_hints.get("length_profile") or "").strip()
        if length_profile_hint:
            hint_bits.append(f"Länge={length_profile_hint}")
        if hint_bits:
            parts.append("Rewrite-Hinweise: " + ", ".join(hint_bits))
    if not parts:
        return ""
    return (
        "STORY CARD (verbindliche Faktenbasis — keine Erfindungen darüber hinaus):\n"
        + "\n".join(parts)
        + "\n"
    )


async def rewrite_to_german(
    original_title: str,
    original_content: str,
    source_language: str,
    content_type: str,
    key_phrases: list[str],
    openai_api_key: str,
    deepseek_api_key: str = "",
    provider_order: list[tuple[str, str, str]] | None = None,
    length_profile: str = "standard",
    source_url: str = "",
    story_card: dict | None = None,
) -> RewriteResult:
    """Rewrite to German master. Falls back to DeepSeek if OpenAI fails."""
    length_hint = {
        # AP-Wire-Stil: knappe Meldung, nur Kerninformation
        "brief":    "90–170 Wörter im body (Kurzmeldung, nur Kernfakten, kein Kontext-Ausbau, keine Deutung)",
        # BBC/Reuters-Stil: vollständige Nachricht mit Kontext
        "standard": "300–500 Wörter im body (vollständige Nachricht, wichtigster Kontext, ein bis zwei Reaktionen)",
        # Quality-Press-Stil: ausführlicher Hintergrundartikel
        "long":     "600–900 Wörter im body (ausführlich, Hintergrund, Einordnung, mehrere Quellen)",
        # Analytical deep-dive: wöchentliche Synthese / Leitartikel
        "analysis": "900–1 400 Wörter im body (analytischer Leitartikel, Thesen, Quellen-Cluster, Einordnung)",
    }.get(length_profile, "300–500 Wörter im body")

    # Determine the publication name for attribution phrases
    pub_name = source_name_from_url(source_url)
    source_line = f"Primärquelle: {pub_name} ({source_url})" if pub_name else f"Primärquelle: {source_url}" if source_url else ""

    # Token budget grows with article length
    max_tok = {"brief": 1024, "standard": 1536, "long": 2560, "analysis": 3500}.get(length_profile, 1536)

    source_word_count = len((original_content or "").split())
    if source_word_count < 35:
        return _safe_ultrathin_rewrite(original_title, original_content, source_url)

    thin_source_guard = ""
    if source_word_count < 120:
        thin_source_guard = (
            "ULTRADÜNNE QUELLE: Schreibe nur eine kompakte Meldung mit 2–4 kurzen Absätzen. "
            "Keine Analyse, keine Folgen, keine Motive, keine nächsten Schritte, keine Parteizugehörigkeiten "
            "oder Vornamen ergänzen, wenn sie nicht im Original stehen. "
            "Wenn das Original nur Miersch/Söder nennt, schreibe nur Miersch/Söder."
        )

    story_card_block = _format_story_card_block(story_card)
    if story_card_block:
        # When a card is present, mention category in the length hint so the
        # model picks structure intelligently (analysis vs reportage vs
        # live-summary). We do not OVERRIDE length_profile here — that is
        # already chosen upstream — but we surface the card-suggested
        # structure as a soft hint inside the prompt body.
        pass

    user_prompt = f"""Quellensprache: {source_language}
Content-Typ: {content_type}
Schlüsselbegriffe: {", ".join(key_phrases)}
Ziel-Länge: {length_hint}
{source_line}
{thin_source_guard}

{story_card_block}
ORIGINAL TITEL:
{original_title}

ORIGINAL INHALT:
{original_content[:6000]}

Erstelle einen professionellen deutschen Nachrichtenartikel.
Faktenregeln:
- Tief umschreiben, aber keine neuen Fakten hinzufügen.
- Datum/Zeit nur übernehmen, wenn sie im Original ausdrücklich stehen.
- Relative Zeitangaben nicht in konkrete Daten umrechnen.
- Vornamen, Amtsbezeichnungen, Ziele, Motive, Folgen und nächste Schritte nur übernehmen, wenn sie im Original ausdrücklich stehen.
- Bei dünner Quelle keine allgemeine Bedeutung aufblasen. Keine Sätze wie „Die Geschichte ist wichtig, weil ...".
- Unbekannte Details weglassen, nicht auffüllen.
- Primärquelle (oben angegeben) bei erster Erwähnung mit Formel nennen: „Wie [Quelle] berichtet, …" o. ä.
Gib zurück: {{"title": "...", "lead": "ein Satz / 1–2 Sätze Teaser", "body": "vollständiger Artikel"}}"""

    source_text = f"{original_title}\n{original_content}"

    candidates = provider_order or [
        ("openai", openai_api_key, "gpt-4o-mini"),
        ("deepseek", deepseek_api_key, "deepseek-chat"),
    ]
    provider_errors: list[str] = []
    for provider, api_key, model in candidates:
        if not api_key:
            continue
        if provider == "deepseek":
            result = await _call_deepseek(user_prompt, api_key, source_text, max_tok, model or "deepseek-chat")
        else:
            result = await _call_openai(user_prompt, api_key, source_text, max_tok, model or "gpt-4o-mini")
        if result.success:
            result.provider = provider
            result.model = model or ("deepseek-chat" if provider == "deepseek" else "gpt-4o-mini")
            return result
        provider_errors.append(f"{provider}/{model or 'default'}: {result.error}")
        logger.warning("Rewrite via %s failed: %s", provider, result.error)

    return RewriteResult(error="All AI providers failed: " + "; ".join(provider_errors))


def _safe_ultrathin_rewrite(original_title: str, original_content: str, source_url: str = "") -> RewriteResult:
    """Build a non-hallucinated short DE brief when the feed exposes only a title/excerpt."""
    source_name = source_name_from_url(source_url) or "die Quelle"
    title = _clean_text(original_title)
    title_core = _title_without_section_prefix(title)
    excerpt = _clean_text(original_content)

    lead = title_core.rstrip(".") + "." if title_core else excerpt.rstrip(".") + "."
    paragraphs: list[str] = []
    if excerpt:
        paragraphs.append(f"{source_name} berichtet: {excerpt.rstrip('.')}.")
    if title_core and title_core.lower() not in excerpt.lower():
        paragraphs.append(f"Die Kurzmeldung nennt außerdem: {title_core}.")
    paragraphs.append("Weitere Details nennt die Kurzmeldung nicht.")

    return RewriteResult(
        title_de=title_core or title,
        lead_de=lead,
        body_de="\n\n".join(paragraphs),
        success=True,
        provider="deterministic",
        model="ultrathin-source-guard",
    )


def _title_without_section_prefix(title: str) -> str:
    if " - " in title:
        return title.split(" - ", 1)[1].strip()
    return title


def _clean_text(text: str) -> str:
    cleaned = re.sub(r"<img\b[^>]*>", " ", text or "", flags=re.I | re.S)
    cleaned = re.sub(r"<figure\b.*?</figure>", " ", cleaned, flags=re.I | re.S)
    cleaned = re.sub(r"<[^>]+>", " ", cleaned)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    return cleaned


async def _call_openai(user_prompt: str, api_key: str, source_text: str, max_tokens: int = 1536, model: str = "gpt-4o-mini") -> RewriteResult:
    try:
        client = AsyncOpenAI(api_key=api_key)
        kwargs = {
            "model": model,
            "messages": [
                {"role": "system", "content": _SYSTEM_PROMPT},
                {"role": "user",   "content": user_prompt},
            ],
            "response_format": {"type": "json_object"},
        }
        if model.startswith(("gpt-5", "o")):
            # openai==1.14 in the worker venv does not expose this newer
            # Chat Completions field as a named argument; extra_body forwards it.
            kwargs["extra_body"] = reasoning_extra_body(model, max(max_tokens * 2, 4096))
        else:
            kwargs["temperature"] = 0.1
            kwargs["max_tokens"] = max_tokens
        response = await client.chat.completions.create(**kwargs)
        raw = completion_text(response)
        if not raw.strip():
            raise ValueError(f"empty OpenAI response ({completion_debug(response)})")
        return _parse_json_result(raw, source_text)
    except Exception as exc:
        logger.warning("OpenAI rewrite failed: %s", exc)
        return RewriteResult(error=str(exc))


async def _call_deepseek(user_prompt: str, api_key: str, source_text: str, max_tokens: int = 1536, model: str = "deepseek-chat") -> RewriteResult:
    try:
        client = AsyncOpenAI(
            api_key=api_key,
            base_url="https://api.deepseek.com/v1",
        )
        response = await client.chat.completions.create(
            model=model,
            messages=[
                {"role": "system", "content": _SYSTEM_PROMPT},
                {"role": "user",   "content": user_prompt},
            ],
            response_format={"type": "json_object"},
            temperature=0.1,
            max_tokens=max_tokens,
        )
        raw = response.choices[0].message.content or ""
        return _parse_json_result(raw, source_text)
    except Exception as exc:
        logger.warning("DeepSeek rewrite failed: %s", exc)
        return RewriteResult(error=str(exc))


def _parse_json_result(raw: str, source_text: str) -> RewriteResult:
    import json
    try:
        data = json.loads(raw)
        result = RewriteResult(
            title_de=str(data.get("title", "")).strip(),
            lead_de=str(data.get("lead", "")).strip(),
            body_de=str(data.get("body", "")).strip(),
            success=True,
        )
        result = _strip_unsupported_first_names(result, source_text)
        result = _normalize_german_style(result)
        unsupported = _unsupported_explicit_dates(
            f"{result.title_de}\n{result.lead_de}\n{result.body_de}",
            source_text,
        )
        if unsupported:
            return RewriteResult(error=f"Unsupported explicit date in AI rewrite: {', '.join(unsupported)}")
        unsupported_names = _unsupported_generated_full_names(
            f"{result.title_de}\n{result.lead_de}\n{result.body_de}",
            source_text,
        )
        if unsupported_names:
            return RewriteResult(error=f"Unsupported full name in AI rewrite: {', '.join(unsupported_names)}")
        return result
    except Exception as exc:
        return RewriteResult(error=f"JSON parse failed: {exc}")


_DE_TO_EN_MONTHS = {
    "januar": "january", "februar": "february", "märz": "march", "maerz": "march",
    "april": "april", "mai": "may", "juni": "june", "juli": "july",
    "august": "august", "september": "september", "oktober": "october",
    "november": "november", "dezember": "december",
}

def _unsupported_explicit_dates(generated_text: str, source_text: str) -> list[str]:
    source = source_text.lower()
    generated = generated_text.lower()
    matches = re.findall(
        r"\b(\d{1,2})\.\s*(januar|februar|m[äa]rz|april|mai|juni|juli|august|september|oktober|november|dezember)\s+(\d{4})\b",
        generated,
        flags=re.IGNORECASE,
    )
    unsupported: list[str] = []
    for day, month_de, year in matches:
        month_de_norm = month_de.lower().replace("ä", "ä")
        full_de = f"{day}. {month_de_norm} {year}"
        # German format: "11. april 2026"
        if full_de in source:
            continue
        # English format: "april 11, 2026" or "11 april 2026"
        month_en = _DE_TO_EN_MONTHS.get(month_de_norm, month_de_norm)
        if f"{month_en} {day}, {year}" in source or f"{day} {month_en} {year}" in source:
            continue
        # Numeric: "11.04.2026" or "2026-04-11"
        month_num = list(_DE_TO_EN_MONTHS.keys()).index(month_de_norm) + 1 if month_de_norm in _DE_TO_EN_MONTHS else 0
        if month_num:
            if f"{day}.{month_num:02d}.{year}" in source or f"{year}-{month_num:02d}-{int(day):02d}" in source:
                continue
        unsupported.append(f"{day}. {month_de} {year}")
    return sorted(set(unsupported))


_NAME_SKIP_LAST_WORDS = {
    "Deutschland", "Europa", "Ukraine", "Union", "Bundestag", "Bundesrat",
    "Kabinett", "Krankenversicherung", "Krankenkassen", "Deutschlandfunk",
}


def _strip_unsupported_first_names(result: RewriteResult, source_text: str) -> RewriteResult:
    """Remove model-added first names when the source only gives a surname."""
    title = _strip_unsupported_first_names_from_text(result.title_de, source_text)
    lead = _strip_unsupported_first_names_from_text(result.lead_de, source_text)
    body = _strip_unsupported_first_names_from_text(result.body_de, source_text)
    return RewriteResult(
        title_de=title,
        lead_de=lead,
        body_de=body,
        success=result.success,
        error=result.error,
        provider=result.provider,
        model=result.model,
    )


def _normalize_german_style(result: RewriteResult) -> RewriteResult:
    return RewriteResult(
        title_de=_normalize_german_text(result.title_de),
        lead_de=_normalize_german_text(result.lead_de),
        body_de=_normalize_german_text(result.body_de),
        success=result.success,
        error=result.error,
        provider=result.provider,
        model=result.model,
    )


def _normalize_german_text(text: str) -> str:
    cleaned = text
    replacements = {
        "zum dritten Eltern geworden": "zum dritten Mal Eltern geworden",
        "zum dritten Vater geworden": "zum dritten Mal Vater geworden",
        "ist der Evgeny Vinokurov": "ist Evgeny Vinokurov",
        "seine Nina": "seine Partnerin Nina",
        "das Paar erwartet nunmehr sein drittes Kind": "das Paar hat sein drittes Kind bekommen",
        "das Paar erwartet sein drittes Kind": "das Paar hat sein drittes Kind bekommen",
        "„Let’s Dance“-Evgeny Vinokurov": "„Let’s Dance“-Profi Evgeny Vinokurov",
        "\"Let’s Dance\"-Evgeny Vinokurov": "\"Let’s Dance\"-Profi Evgeny Vinokurov",
    }
    for src, dst in replacements.items():
        cleaned = cleaned.replace(src, dst)
    cleaned = re.sub(
        r"\bist\s+([A-ZÄÖÜ][A-Za-zÄÖÜäöüß'’ʼ.-]+(?:\s+[A-ZÄÖÜ][A-Za-zÄÖÜäöüß'’ʼ.-]+){0,3})\s+zum dritten Mal Eltern geworden\b",
        r"ist \1 zum dritten Mal Vater geworden",
        cleaned,
    )
    return cleaned


def _strip_unsupported_first_names_from_text(text: str, source_text: str) -> str:
    cleaned = text
    for full, last in _unsupported_generated_full_name_pairs(text, source_text):
        cleaned = re.sub(rf"\b{re.escape(full)}\b", last, cleaned)
    return cleaned


def _polish_german_source_attribution(result: RewriteResult) -> RewriteResult:
    return RewriteResult(
        title_de=result.title_de,
        lead_de=_move_german_source_attribution(result.lead_de),
        body_de=_move_german_source_attribution(result.body_de),
        success=result.success,
        error=result.error,
        provider=result.provider,
        model=result.model,
    )


def _move_german_source_attribution(text: str) -> str:
    if "\n" not in text:
        return _move_german_source_attribution_sentence(text)
    paragraphs = [
        _move_german_source_attribution_sentence(part.strip())
        for part in re.split(r"\n{2,}", text)
        if part.strip()
    ]
    return "\n\n".join(paragraphs)


def _move_german_source_attribution_sentence(text: str) -> str:
    patterns = [
        (r"^\s*Wie\s+([^,]+)\s+berichtet,\s*(.+)$", "berichtet"),
        (r"^\s*Nach Angaben von\s+([^,]+),\s*(.+)$", "nach Angaben von"),
        (r"^\s*([^,]+)\s+zufolge,\s*(.+)$", "zufolge"),
    ]
    for pattern, formula in patterns:
        match = re.match(pattern, text, flags=re.I | re.S)
        if not match:
            continue
        source = match.group(1).strip()
        rest = match.group(2).strip()
        if not source or not rest:
            return text
        rest = rest[:1].upper() + rest[1:] if rest else rest
        rest = rest.rstrip()
        if rest.endswith((".", "!", "?", "…")):
            rest = rest[:-1].rstrip()
        if formula == "zufolge":
            return f"{rest}, {source} zufolge."
        return f"{rest}, {formula} {source}."
    return text


def _unsupported_generated_full_names(generated_text: str, source_text: str) -> list[str]:
    return sorted({full for full, _last in _unsupported_generated_full_name_pairs(generated_text, source_text)})


def _unsupported_generated_full_name_pairs(generated_text: str, source_text: str) -> list[tuple[str, str]]:
    source = source_text or ""
    unsupported: list[tuple[str, str]] = []
    full_name_pattern = re.compile(
        r"\b([A-ZÄÖÜ][A-Za-zÄÖÜäöüß-]{2,})\s+([A-ZÄÖÜ][A-Za-zÄÖÜäöüß-]{2,})\b"
    )
    for match in full_name_pattern.finditer(generated_text):
        first = match.group(1)
        last = match.group(2)
        if first.isupper() or last.isupper():
            continue
        if last in _NAME_SKIP_LAST_WORDS:
            continue
        full = f"{first} {last}"
        if re.search(rf"\b{re.escape(full)}\b", source):
            continue
        # If the source only names the surname, adding a first name is a new fact.
        if re.search(rf"\b{re.escape(last)}\b", source) and not re.search(rf"\b{re.escape(first)}\b", source):
            unsupported.append((full, last))
    return sorted(set(unsupported))
