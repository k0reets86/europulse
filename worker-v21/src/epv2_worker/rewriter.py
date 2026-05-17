"""
EuroPulse AutoPilot v2.1 — DE Master Rewriter
Rewrites article into German using OpenAI GPT-4o Mini (primary) or DeepSeek (fallback).
"""
from __future__ import annotations

import datetime as _dt
import logging
import re
from dataclasses import dataclass, field
from urllib.parse import urlparse

from openai import AsyncOpenAI

from .openai_compat import (
    completion_cached_tokens,
    completion_debug,
    completion_text,
    completion_total_tokens,
    reasoning_extra_body,
)

logger = logging.getLogger(__name__)

# R8 Phase 2 2026-05-14: spaCy de_core_news_lg для NER-based filtering
# fabricated_name candidates. Eliminates German compound noun FP epidemic
# (Bundesverteidigungsminister, Russlands Angriffskrieg etc.) → их NER не
# определит как PERSON. Lazy load (один раз на process) — ~2s startup.
_DE_NLP = None
_DE_NLP_LOAD_FAILED = False

def _get_de_nlp():
    """Lazy-load spaCy DE model. Returns None если spacy не установлен или fail'нул."""
    global _DE_NLP, _DE_NLP_LOAD_FAILED
    if _DE_NLP is not None:
        return _DE_NLP
    if _DE_NLP_LOAD_FAILED:
        return None
    try:
        import spacy  # type: ignore
        _DE_NLP = spacy.load("de_core_news_lg")
        logger.info("spaCy de_core_news_lg loaded for fabricated_name NER filtering")
        return _DE_NLP
    except Exception as exc:  # noqa: BLE001
        logger.warning("spaCy DE model unavailable, falling back to heuristic-only: %s", exc)
        _DE_NLP_LOAD_FAILED = True
        return None

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
    # Carded lead-magnet — eine einzelne, geschlossene Schlagzeilenzeile
    # für Karten auf der Startseite. 110–130 Zeichen, keine Abkürzungen,
    # keine offenen Sätze. Wird vom Rewriter zusätzlich zum Lead generiert
    # und durch den Übersetzer in UK/EN überführt.
    card_lead_de: str = ""
    success: bool = False
    error: str = ""
    provider: str = ""
    model: str = ""
    tokens: int = 0
    # R5 2026-05-14: OpenAI prompt caching tracking — 50% discount применяется
    # на cached_tokens. DeepSeek не отдаёт cached → 0.
    cached_tokens: int = 0
    # Anti-plagiarism gate (architecture phase 3). Surface the score so
    # callers can log it / decide on regeneration. Default leaves the
    # gate inactive when not computed.
    uniqueness_pct: float = 100.0
    uniqueness_passed: bool = True
    uniqueness_reason: str = ""
    # B3 (2026-05-12): soft validator warnings. Раньше валидатор date/name
    # бросал error и REJECT'ил весь rewrite (item уходил в retry → cap →
    # manual_review). Теперь validators могут возвращать warning'и, и rewrite
    # доходит до publisher. PHP gate решает уровень severity для каждого:
    #  - дата явная фабрикация (год в будущем, нет в source) → может block'ить
    #  - дата current-year edge case → можно опубликовать с warning
    # warnings is a list of dicts: [{"kind": "date|name", "value": "...", "severity": "soft|hard"}]
    warnings: list = field(default_factory=list)


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

ZAHLEN UND EINHEITEN — KRITISCH (gegen Pattern «17,8 Euro» вместо «17,8 Mrd Euro»):
- Wenn Original eine Zahl mit Einheit nennt («3,5 Milliarden Euro», «17,8 Mrd»,
  «41 Prozent», «100 Tausend», «5 Mio Dollar», «800 km»), übertrage SOWOHL
  Zahl als auch Einheit. NIE Einheit weglassen. «17,8» ohne «Milliarden» —
  абсурд (получается 17 Euro вместо миллиардов).
- Bei Verlustzahlen Krieg / Statistiken: год/период обязательно («350.000
  bis Ende 2025», не просто «350.000»). Иначе данные кажутся свежими, но
  отражают исторический период.
- Wenn Original gibt einen Zeitraum («bis Mai 2023», «in den letzten 12
  Monaten»), übertrage этот Zeitraum в текст. Nicht einfach «aktuell»
  schreiben.

KONKRETE ZAHLEN — KEINE ERFINDUNGEN (CRITICAL, gegen Pattern Sylt-Preise / DAX-Stand / Auto-Preise):
- Wenn das Original (primary content + Story-Card.key_facts + supporting
  sources) eine spezifische Zahl NICHT enthält — DARFST DU SIE NICHT
  ERFINDEN. Auch nicht «zur Anschaulichkeit». Auch nicht «basierend auf
  ähnlichen Quellen». Auch nicht aus Trainingswissen.
- VERBOTEN: konkrete Preise («9.922 Euro pro Quadratmeter»), Index-Stände
  («DAX schloss bei 24.338 Zählern»), Prozent-Werte («36,4 Prozent
  Energiekosten»), Gehälter («20.000 UAH Grundgehalt»), wenn Quelle diese
  Zahlen NICHT EXPLIZIT enthält.
- ERLAUBT bei fehlender konkreter Zahl: generische Begriffe verwenden —
  «im fünfstelligen Bereich», «mehrere tausend Euro», «im einstelligen
  Prozent-Bereich», «rund», «etwa», «überdurchschnittlich», «in der
  Grössenordnung von». Verallgemeinerung ist EHRLICHER als Erfindung.
- DOPPELT-PRÜFE: vor jeder konkreten Zahl ≥1000 oder jedem konkreten
  Prozentwert frage dich «steht diese Zahl wortwörtlich im Original?»
  Wenn Nein → ersetze durch generische Formulierung.
- Beispiel-Anwendung: wenn Original sagt «Quadratmeter Sylt für mehr als
  12.500 Euro» — DARFST DU «mehr als 12.500 Euro» schreiben (steht im
  Original). DARFST DU NICHT «zweistellige Rückgänge auf 9.922 Euro»
  schreiben (steht NICHT im Original).
- Wenn dünne Quelle (kurzer RSS-Teaser, paywalled article ohne content) —
  bleibe BEI DEN FAKTEN DES TEASERS. Lieber kürzerer Artikel mit echten
  Fakten als langer mit erfundenen Details.

AKTEUR-KOHÄRENZ — KRITISCH (gegen Pattern «новый PM описан как часть старой
системы»):
- Wenn die Story über Machtwechsel, Sturz, Umsturz oder Reformbewegung
  ist: der NEUE Akteur ist OPPONENT alter Strukturen, NICHT Teil davon.
- Beispiel: «Magyar löst Orbán ab» bedeutet Magyar GEGEN Orbán-System,
  nicht «Magyar in der Tradition der Orbán-Loyalisten».
- Wenn Original und Story-Card von «Systemwechsel», «Abwahl», «Umbruch»
  sprechen — der neue Akteur trägt diese Veränderung, ist nicht ihr Teil.
- Lies Story-Card.entities_people[*].role и .editorial_match сорок раз
  чтобы понять кто протагонист, кто антагонист.

NAMEN UND FUNKTIONEN — KEINE ERFINDUNGEN:
- NIEMALS Namen einer Person erfinden, die im Original nicht steht. Wenn
  «der designierte Ministerpräsident» — schreibe «der designierte
  Ministerpräsident» (allgemein), NICHT «Cem Özdemir» из общего знания.
- NIEMALS Rollen / Funktionen erfinden («Bundeskanzler X» wenn Original
  nur «X» sagt — und X ist eigentlich nur Abgeordneter).
- NIEMALS Politiker mit Aktivitäten verbinden, die Original nicht erwähnt
  («Brown wird in die Regierung geholt» — wenn Original nur sagt «Starmer
  sucht Erfahrung», ohne konkrete Person).

RUSSLAND-UKRAINE — verbindliche redaktionelle Linie (KEINE Ausnahmen):
EuroPulse berichtet über Russlands vollumfänglichen Angriffskrieg gegen die Ukraine
seit Februar 2022. Russland ist der Aggressor. Das ist kein Standpunkt, sondern Fakt.
- PFLICHT-Vokabular: „Russlands Angriffskrieg", „russische Aggression", „russische Besatzer",
  „Kriegsverbrechen", „Russlands Raketenangriffe", „Wladimir Putin / Kremlchef Putin".
- VERBOTEN: „Spannungen zwischen den beiden Ländern", „Konflikt" allein ohne Aggressor,
  „Streit", „bilaterale Krise", „Eskalation" als Zustand, „beide Seiten" als gleichwertig,
  „mutmaßlich" wenn Russland selbst zugibt.
- Spekulationen wie „dies könnte zeigen, dass die Beziehungen angespannt sind", „möglicherweise…",
  „dies deutet auf Annäherung hin" sind in jedem militärischen / kriegsbezogenen Kontext
  STRENG VERBOTEN — nur konkrete Fakten ohne weichspülende Interpretation.
- Wenn die Quelle eine neutralisierende Formulierung verwendet — KORRIGIEREN, nicht übernehmen.
- VERBOTEN: framing einer Putin-Einladung an Selenskyj nach Moskau als
  «Möglichkeit, in die russische Hauptstadt zu reisen» oder «Selenskyj hat
  die Möglichkeit». Это war-time peace overture от агрессора. Korrekte
  Formulierung: «Putin hat Selenskyj nach Moskau eingeladen — eine
  Provokation, da russische Truppen weiter ukrainisches Territorium
  besetzen» oder ähnlich. Никогда не описывать поездку лидера-жертвы в
  столицу агрессора als nüchterne Optionsfrage.

ABLEHNUNG / ZURÜCKWEISUNG BEWAHREN — KRITISCH (gegen Pattern «softening»):
- Wenn Quelle prägnante Ablehnung enthält («Kyiv rejected», «impossible»,
  «Moscow = capital of aggressor state», «not acceptable»), übertrage diese
  Ablehnung in voller Stärke. NIE softeneть в «X считает Optionen» /
  «keine weiteren Details bekannt» / «mögliche Reise» / «Reaktionen
  gemischt».
- Wenn Quelle Aggressor-Status explizit nennt («aggressor state», «country
  of aggressor», «Россия — страна-агрессор»), эту атрибуцию ОБЯЗАТЕЛЬНО
  переносить в текст. Дропать её для «нейтрального тона» — undermined
  editorial position EuroPulse.
- Beispiele правильного перевода:
  ✓ «Київ отверг встречу в Москве, столице государства-агрессора» →
    «Kyiv lehnt Treffen in Moskau ab — der Hauptstadt des Aggressorstaats»
  ✗ «Zelensky hat die Möglichkeit, in die russische Hauptstadt zu reisen»
  ✗ «keine weiteren Details zu seiner möglichen Reise»
- Если у источника conflict / war involves clear aggressor-victim, в нашем
  тексте это distinction ОБЯЗАТЕЛЬНО видно. «Beide Seiten» / «Konflikt» /
  «Streit» — VERBOTEN.
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
- JEDER Absatz braucht mindestens 3 vollständige Sätze und mindestens
  1 KONKRETEN Fakt (Name, Datum, Zahl, Ort, Funktion, wörtliches Zitat).
  Absatz mit nur 1 Satz oder ohne harte Fakten ist VERBOTEN.
- Wenn der Stoff es trägt: 2–3 H2-Zwischenüberschriften zur Gliederung längerer Texte
  (Format: <h2>Untertitel</h2>). Eine H2 reicht aber nie als reines Keyword-Stuffing —
  sie soll inhaltlich den nächsten Absatz beschreiben.
- Konkrete Zahlen und Eigennamen früh und mehrfach im Text wiederholen — gut für
  Such-Indexierung und Entitäts-Erkennung.
- Letzten Absatz für Einordnung / Kontext / Folgen, falls die Quelle das hergibt.

ANTI-FÜLLTEXT-REGELN (gegen leeren, "weichen" Text):
- VERBOTEN, weil sie nichts sagen:
  * „Diese Entscheidung könnte … beeinflussen / hat Folgen für …" ohne konkrete
    benannte Folge mit Zahl/Datum/Akteur.
  * „Mögliche Konsequenzen / mögliche Auswirkungen / könnte sich auswirken auf"
    als Spekulation ohne Quelle.
  * „Eine offizielle Bestätigung / weitere Informationen liegen noch nicht vor"
    als Absatz-Inhalt — solche Meta-Sätze gehören NICHT in den Body. Wenn die
    Quelle dünn ist: körzer schreiben, nicht Lücken mit „noch nicht bekannt"
    füllen.
  * „Diese Entwicklung wirft Fragen auf", „Es bleibt abzuwarten", „Die Lage
    bleibt angespannt", „Beobachter sehen darin …" — alles raus.
  * Wiederholungen des Titels in anderen Worten („Der Bundestag plant den
    Ausstieg" → später „Der Plan zum Ausstieg des Bundestags …"). Jeder
    Absatz muss NEUE Information bringen.
- ERLAUBT (und gewünscht), wenn die Quelle es hergibt:
  * Hintergrund: warum diese Entscheidung jetzt — vorhergehende Beschlüsse,
    Haushaltslage mit konkreten Zahlen, frühere Kostenexplosionen.
  * Beteiligte Akteure mit Funktion: „Bundestags-Bauausschuss-Vorsitzender
    [Name] (CDU)", „Haushaltsausschuss unter [Name] (Grüne)" — nicht nur
    „der Ausschuss".
  * Konkrete Zahlen aus der Quelle: Gesamtkosten, Kostensteigerung in %,
    bisher ausgegebene Summen, Termine.
  * Reaktionen mit Quellen-Attribution: „[Person], [Funktion], [Quelle]:
    ‚wörtliches Zitat'."
  * Nächster Schritt mit Datum, falls genannt: „Die Entscheidung soll bis
    [Datum] fallen."

WENN DIE QUELLE DÜNN IST (kurze Pressemitteilung, knapper Bericht):
- Schreibe lieber 150 dichte Wörter als 400 verwässerte. Brief = brief.
- KEINE Spekulationen, KEINE allgemeinen Zusammenhänge ohne Quellenbeleg,
  KEINE „könnte / möglicherweise / dürfte"-Sätze.
- Wenn die Story nur 3 harte Fakten hat, schreibe genau diese 3 Fakten —
  nicht 8 Sätze um sie herum.

OUTPUT-FORMAT:
Ausgabe ausschließlich als gültiges JSON mit den Feldern: title, lead, card_lead, body.
- title: 50–80 Zeichen, faktisch, kein Clickbait, Hauptkeyword möglichst weit vorn.
- lead: 1–2 Sätze, beantwortet Wer/Was/Wann/Wo, ohne Wiederholung des Titels.
- card_lead: GENAU EIN vollständiger, geschlossener Satz, 110–130 Zeichen.
  Karten-Lead-Magnet für die Startseite — er muss ohne weiteres Lesen Sinn
  ergeben und Lust auf den Artikel machen. NICHT identisch zum Lead, NICHT
  identisch zum Titel. KEINE Abkürzungen mit Punkt im Inneren („8. Mai",
  „z. B.", „St. Petersburg", „10. Juli" — bitte ausschreiben oder umformulieren).
  KEIN Satz, der mit Präposition / Konjunktion / Artikel / Hilfsverb endet.
  KEINE drei Punkte am Ende — der Satz schließt mit einem klassischen Punkt,
  Frage- oder Ausrufezeichen ab. Eigennamen vollständig (Vor- und Nachname,
  oder Funktion + Nachname), niemals abgeschnitten.
  KEINE QUELLENANGABE im card_lead — keine Phrasen wie „Wie X berichtet",
  „nach Angaben von X", „X zufolge", „laut X", „X mitteilt". Die Quelle
  gehört in den Body-Text, nicht in den Karten-Hook. Der card_lead muss
  die Nachricht selbst tragen, nicht die Tatsache dass sie irgendwo gemeldet
  wurde.
- body: HTML-Absätze (<p>...</p>) plus optionale <h2>Zwischenüberschrift</h2>;
  keine Markdown-Sterne, keine Listen außer wenn die Quelle eine echte Liste enthält.

ABSOLUT VERBOTEN — der erste Satz des body darf NICHT eine umformulierte
Variante des Titels sein. Konkret:
- Wenn Titel mit "Bundestag plant Ausstieg aus …" beginnt, darf body NICHT
  mit "Der Bundestag plant, sich aus … zurückzuziehen" anfangen.
- Wenn Titel mit "X kritisiert Y" beginnt, darf body NICHT mit "X äußerte
  Kritik an Y" oder "X hat Y kritisiert" anfangen.
Der erste Satz body MUSS einen anderen Aufhänger setzen — ein konkretes
Detail, eine Reaktion, ein Hintergrundfakt, ein Datum, ein Ort, ein Zitat,
oder eine Folge der Nachricht. Titel sagt WAS, body-Anfang setzt KONTEXT.
Der lead-Satz steht zwischen Titel und body-Anfang — er muss alle drei
Ebenen unterscheiden: Titel (kurze Schlagzeile) → lead (1-2 Sätze
Wer/Was/Wann/Wo) → body-Anfang (konkretes Detail, KEIN Echo des Titels).

Lead darf NICHT leer sein. Lead ist ein vollständiger, abgeschlossener
Satz (oder zwei kurze) der die Nachricht zusammenfasst, ohne Titel-Worte
zu wiederholen."""


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
    editorial_match = str(card.get("editorial_match") or "").strip().lower()
    if editorial_match:
        editorial_reason = str(card.get("editorial_reason") or "").strip()
        ed_line = f"Redaktionelle Einordnung: {editorial_match}"
        if editorial_reason:
            ed_line += f" — {editorial_reason}"
        if editorial_match == "match":
            ed_line += ". Tonfall: konfident, klar, journalistisch — Story passt redaktionell ohne Hedging."
        elif editorial_match == "borderline":
            ed_line += ". Tonfall: nüchterner, weniger Eigeninterpretation — Story ist redaktionell grenzwertig, lieber zurückhaltend."
        elif editorial_match == "reject_low_value":
            ed_line += ". WARNUNG: Story passt redaktionell nicht — wenn dennoch geschrieben wird, knapp halten und keinen Mehrwert vortäuschen."
        parts.append(ed_line)
    estimate = str(card.get("publishable_estimate") or "").strip().lower()
    if estimate:
        estimate_line = f"Publish-Erwartung: {estimate}"
        if estimate in ("high", "medium"):
            estimate_line += " — Story trägt selbständig; ausreichend Substanz für vollständigen Artikel."
        elif estimate == "low":
            estimate_line += " — Substanz dünn; lieber kompakt halten, keine Padding-Sätze."
        elif estimate == "reject":
            estimate_line += " — Substanz unzureichend; nur knapp das Faktische, keine Auslegung."
        parts.append(estimate_line)
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
    kind: str = "",
    rubric_slug: str = "",
    dossier_block: str = "",
) -> RewriteResult:
    """Rewrite to German master. Falls back to DeepSeek if OpenAI fails.

    When both ``kind`` (KIND_SPECS slug) and ``rubric_slug`` (WP category slug)
    are provided, the composed type×rubric prompt matrix from
    ``epv2_worker.prompts`` is used. Otherwise the legacy length-profile
    prompt is used (backwards compatible for older callers).
    """
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

    # Phase 2.3: composed type×rubric prompt matrix when both signals present.
    if kind and rubric_slug:
        from .prompts import compose_rewrite_prompt
        # Combine the legacy thin-source guard into the dossier block so the
        # composed prompt still warns the model about ultra-thin sources.
        dossier_combined = dossier_block
        if thin_source_guard:
            dossier_combined = (dossier_block + "\n\n" + thin_source_guard).strip() if dossier_block else thin_source_guard
        user_prompt = compose_rewrite_prompt(
            kind=kind,
            rubric_slug=rubric_slug,
            story_card_block=story_card_block,
            dossier_block=dossier_combined,
            original_title=original_title,
            original_content=original_content,
            source_url=source_url,
            source_language=source_language,
            publication_name=pub_name,
        )
    else:
        # Legacy length-profile prompt (used when caller does not supply
        # kind/rubric — keeps older test fixtures working).
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
Gib zurück: {{"title": "...", "lead": "ein Satz / 1–2 Sätze Teaser", "card_lead": "ein einziger geschlossener Satz, 110–130 Zeichen, ohne Abkürzungen, für die Startseiten-Karte", "body": "vollständiger Artikel"}}"""

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
            result = await _call_deepseek(user_prompt, api_key, source_text, max_tok, model or "deepseek-chat", story_card=story_card, dossier_block=dossier_block)
        else:
            result = await _call_openai(user_prompt, api_key, source_text, max_tok, model or "gpt-4o-mini", story_card=story_card, dossier_block=dossier_block)
        if result.success:
            result.provider = provider
            result.model = model or ("deepseek-chat" if provider == "deepseek" else "gpt-4o-mini")
            _annotate_uniqueness(result, source_text=source_text, story_card=story_card, language="de")
            return result
        provider_errors.append(f"{provider}/{model or 'default'}: {result.error}")
        logger.warning("Rewrite via %s failed: %s", provider, result.error)

    return RewriteResult(error="All AI providers failed: " + "; ".join(provider_errors))


def _annotate_uniqueness(
    result: RewriteResult,
    *,
    source_text: str,
    story_card: dict | None,
    language: str,
) -> None:
    """Run the anti-plagiarism gate on the just-rewritten DE bundle.

    Architecture phase 3: ≥80% uniqueness against the primary source.
    We score lead+body together (the title is too short to score reliably)
    and tag the result so the pipeline can log + decide on regeneration.
    """
    try:
        from .plagiarism import check_uniqueness
    except Exception:  # pragma: no cover — import-time safety net
        return
    generated = "\n".join(filter(None, [result.lead_de or "", result.body_de or ""])).strip()
    if not generated or not source_text:
        return
    entities: list[str] = []
    if isinstance(story_card, dict):
        for person in story_card.get("entities_people") or []:
            if isinstance(person, dict) and person.get("name"):
                entities.append(str(person["name"]))
        for org in story_card.get("entities_organizations") or []:
            if isinstance(org, dict) and org.get("name"):
                entities.append(str(org["name"]))
        for place in story_card.get("entities_places") or []:
            if place:
                entities.append(str(place))
    verdict = check_uniqueness(
        generated_text=generated,
        source_text=source_text,
        language=language,
        named_entities=entities,
    )
    result.uniqueness_pct = round(verdict.uniqueness_pct, 1)
    result.uniqueness_passed = verdict.passed
    result.uniqueness_reason = verdict.reason


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
        # Ultrathin-Quellen haben keinen separaten Karten-Lead — der Mu-Plugin-
        # Fallback rendert weiterhin aus dem post_excerpt.
        card_lead_de="",
        success=True,
        provider="deterministic",
        model="ultrathin-source-guard",
    )


_CARD_LEAD_MIN_LEN = 60
_CARD_LEAD_MAX_LEN = 200

# Source-attribution patterns — verboten im card_lead. Atributtion gehört
# in den Body, der Karten-Hook trägt die Nachricht selbst.
_CARD_LEAD_SOURCE_ATTRIBUTION_RE = re.compile(
    r"(?ix)"  # case-insensitive, verbose
    r"(?:^|[\s,;—–-])("
    # German
    r"wie\s+[A-ZÄÖÜ][\w\.\-]+\s+(?:berichtet|meldet|mitteilt|schreibt|erkl[äa]rt)"
    r"|nach\s+angaben\s+(?:von|der|des)\s+[A-ZÄÖÜ]"
    r"|nach\s+informationen\s+(?:von|der|des)\s+[A-ZÄÖÜ]"
    r"|laut\s+[A-ZÄÖÜ][\w\.\-]+"
    r"|[A-ZÄÖÜ][\w\.\-]+\s+zufolge"
    r"|wie\s+(?:die|der|das)\s+[A-ZÄÖÜ][\w\.\-]+\s+(?:berichtet|meldet|mitteilt|schreibt)"
    # English
    r"|according\s+to\s+[A-Z]"
    r"|as\s+(?:[A-Z][\w\.\-]+\s+)?reports?"
    r"|[A-Z][\w\.\-]+\s+reports?"
    r"|sources?\s+say"
    r"|per\s+[A-Z][\w\.\-]+"
    # Ukrainian / Russian
    r"|за\s+повідомленням(?:и)?\s+[А-ЯЇІЄҐA-Z]"
    r"|як\s+повідомля[єют][а-яїієґ]*\s+[А-ЯЇІЄҐA-Z]"
    r"|повідомля[єют][а-яїієґ]*\s+[А-ЯЇІЄҐA-Z]"
    r"|за\s+даними\s+[А-ЯЇІЄҐA-Z]"
    r"|за\s+словами\s+[А-ЯЇІЄҐA-Z]"
    r"|пише\s+[А-ЯЇІЄҐA-Z]"
    r"|інформує\s+[А-ЯЇІЄҐA-Z]"
    r"|по\s+(?:сообщению|данным|информации)\s+[А-ЯA-Z]"
    r"|сообщает\s+[А-ЯA-Z]"
    r")"
)


def _sanitize_card_lead(text: str) -> str:
    """Validate the AI-generated card_lead. Return "" if it fails the contract,
    so the mu-plugin renderer falls back to the legacy excerpt path."""
    if not text:
        return ""
    cleaned = re.sub(r"<[^>]+>", " ", text)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    cleaned = cleaned.strip("\"'`«»“”„")
    if not cleaned:
        return ""
    length = len(cleaned)
    if length < _CARD_LEAD_MIN_LEN or length > _CARD_LEAD_MAX_LEN:
        return ""
    if cleaned.endswith("…") or cleaned.endswith("..."):
        return ""
    if cleaned[-1] not in ".!?":
        return ""
    inner = cleaned[:-1]
    # Truncation guards: число-точка ИЛИ abbrev-точка В КОНЦЕ (без следующего
    # слова) → trunc'нуло, reject. В середине дата/abbrev допустимы — это
    # валидный текст («8. Mai 2025», «St. Petersburg», «z. B. dort»). Раньше
    # любое \b\d+\.\s в середине лида роняло 30-60% AI-output'ов в "".
    if re.search(r"\b\d+\.\s*$", inner):
        return ""
    if re.search(r"\b[A-Za-zÄÖÜäöüß]\.\s*$", inner):
        return ""
    # Multi-sentence guard — card_lead должен быть одним предложением.
    # Pattern: terminal punct + space + uppercase letter = новое sentence.
    if re.search(r"[.!?]\s+[A-ZÄÖÜА-ЯЇІЄҐ]", inner):
        return ""
    if _CARD_LEAD_SOURCE_ATTRIBUTION_RE.search(cleaned):
        return ""
    return cleaned


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


async def _call_openai(user_prompt: str, api_key: str, source_text: str, max_tokens: int = 1536, model: str = "gpt-4o-mini", story_card: dict | None = None, dossier_block: str = "") -> RewriteResult:
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
        result = _parse_json_result(raw, source_text, story_card=story_card, supporting_text=dossier_block)
        result.tokens = completion_total_tokens(response)
        result.cached_tokens = completion_cached_tokens(response)
        return result
    except Exception as exc:
        logger.warning("OpenAI rewrite failed: %s", exc)
        return RewriteResult(error=str(exc))


async def _call_deepseek(user_prompt: str, api_key: str, source_text: str, max_tokens: int = 1536, model: str = "deepseek-chat", story_card: dict | None = None, dossier_block: str = "") -> RewriteResult:
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
        result = _parse_json_result(raw, source_text, story_card=story_card, supporting_text=dossier_block)
        result.tokens = completion_total_tokens(response)
        # DeepSeek не отдаёт cached_tokens, completion_cached_tokens вернёт 0.
        result.cached_tokens = completion_cached_tokens(response)
        return result
    except Exception as exc:
        logger.warning("DeepSeek rewrite failed: %s", exc)
        return RewriteResult(error=str(exc))


def _parse_json_result(raw: str, source_text: str, story_card: dict | None = None, supporting_text: str = "") -> RewriteResult:
    import json
    try:
        data = json.loads(raw)
        result = RewriteResult(
            title_de=str(data.get("title", "")).strip(),
            lead_de=str(data.get("lead", "")).strip(),
            body_de=str(data.get("body", "")).strip(),
            card_lead_de=_sanitize_card_lead(str(data.get("card_lead", "")).strip()),
            success=True,
        )
        result = _strip_unsupported_first_names(result, source_text)
        result = _normalize_german_style(result)
        # B3 (2026-05-12): Soft validators. Раньше REJECT'или весь rewrite, теперь
        # annotate в result.warnings. Hard fabrications (date далеко в будущем без
        # source-mention) маркируются severity=hard — PHP publisher может всё ещё
        # block'ить, но мы НЕ теряем item на retry-cap из-за edge cases (yearless
        # current-year, today + relative hint, well-known full name).
        full_text = f"{result.title_de}\n{result.lead_de}\n{result.body_de}"
        try:
            today = _dt.date.today()
        except Exception:
            today = None

        unsupported_dates = _unsupported_explicit_dates(full_text, source_text)
        for dt_str in unsupported_dates:
            severity = "hard"
            # Soft-classify: дата в прошлом дальше 2 лет, или в будущем дальше 3 лет —
            # серьёзная фабрикация. Иначе soft (operator decides).
            try:
                m = re.match(r"(\d{1,2})\.\s*([a-zäöü]+)\s+(\d{4})", dt_str.lower())
                if m and today is not None:
                    month_num = _DE_MONTH_NUM.get(m.group(2), 0)
                    if month_num:
                        date_obj = _dt.date(int(m.group(3)), month_num, int(m.group(1)))
                        diff_days = (date_obj - today).days
                        if -730 <= diff_days <= 1095:
                            severity = "soft"
            except (ValueError, TypeError):
                pass
            result.warnings.append({"kind": "explicit_date", "value": dt_str, "severity": severity})

        unsupported_names = _unsupported_generated_full_names(full_text, source_text)
        for name in unsupported_names:
            # Name validator всегда soft — regex pattern имеет известные false-positive cases
            # (compound nouns + capitalized titles), PHP publisher решает на основе
            # importance / category / source confidence.
            result.warnings.append({"kind": "full_name", "value": name, "severity": "soft"})

        # 2026-05-12 W1.3: proper-noun fabrication guard.
        # ESC post 10149 invented "Linda Lampenius", "Pete Parkkonen", etc. — none in source.
        # R8 Phase 1+2 2026-05-14: severity restored to "hard" после deploy:
        #  - Phase 1 (supporting_text cross-ref) — расширяет trusted_tokens
        #  - Phase 2 (spaCy de_core_news_lg NER) — фильтрует compound nouns
        # Combined reduce FP rate ~85%. Real fabrications all'еще caught.
        # 2026-05-13 epidemic (Russlands Angriffskrieg → flagged as name) теперь
        # impossible — spaCy NER не определяет это как PER entity.
        # 2026-05-17 R8 Phase 3: cross-script fix. Если source — Cyrillic
        # (UA/RU), trusted_tokens regex `[A-ZÄÖÜ]...` НЕ матчит Cyrillic слова,
        # все имена транслитерированные AI'ом ("Wolodymyr Zelensky" из
        # "Володимир Зеленський") flagуются как fabricated. Detector не может
        # надёжно cross-check transliterations без full BGN/PCGN + German
        # transliteration tables. Pragmatic fix: при Cyrillic source severity
        # = "soft" — surface warning в admin, не block publish. spaCy DE NER
        # + Story Card primacy + other quality validators остаются operating
        # as quality signals. Если real fabrication будет — soft warning
        # surfaces it, operator catches на ручной выборке.
        fabricated = _detect_fabricated_proper_nouns(full_text, source_text, story_card, supporting_text=supporting_text)
        source_is_cyrillic = bool(re.search(r"[А-Яа-яЁёІіЇїЄєҐґ]", source_text))
        fabricated_severity = "soft" if source_is_cyrillic else "hard"
        for name in fabricated:
            result.warnings.append({"kind": "fabricated_name", "value": name, "severity": fabricated_severity})

        return result
    except Exception as exc:
        return RewriteResult(error=f"JSON parse failed: {exc}")


_DE_TO_EN_MONTHS = {
    "januar": "january", "februar": "february", "märz": "march", "maerz": "march",
    "april": "april", "mai": "may", "juni": "june", "juli": "july",
    "august": "august", "september": "september", "oktober": "october",
    "november": "november", "dezember": "december",
}

# Direct DE month → numeric mapping. The previous numeric lookup used
# `list(_DE_TO_EN_MONTHS.keys()).index(...) + 1`, which is off-by-one starting
# from April because "maerz" is a duplicate of "märz" in the dict above.
# That made the "11.04.2026" / "2026-04-11" source-match branch skip many
# legitimate dates. Use this explicit map instead.
_DE_MONTH_NUM = {
    "januar": 1, "februar": 2, "märz": 3, "maerz": 3,
    "april": 4, "mai": 5, "juni": 6, "juli": 7,
    "august": 8, "september": 9, "oktober": 10,
    "november": 11, "dezember": 12,
}

_DATE_RELATIVE_HINTS = (
    "heute", "today", "сьогодні", "сегодня", "сьогоднi",
    "gestern", "yesterday", "вчора", "вчера",
    "morgen", "tomorrow", "завтра",
    "soeben", "gerade eben", "kürzlich", "kuerzlich", "vor wenigen", "vor kurzem",
    "tagesaktuell", "stunden", "minuten", "minutes ago", "hours ago",
    "this morning", "this afternoon", "this evening",
    "diesem morgen", "diesem nachmittag", "diesem abend",
    "live", "breaking", "just in",
)


def _unsupported_explicit_dates(generated_text: str, source_text: str) -> list[str]:
    source = source_text.lower()
    generated = generated_text.lower()
    try:
        today = _dt.date.today()
        current_year = today.year
    except Exception:
        today = None
        current_year = 0
    has_relative_hint = any(hint in source for hint in _DATE_RELATIVE_HINTS)
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
        month_num = _DE_MONTH_NUM.get(month_de_norm, 0)
        if month_num:
            if f"{day}.{month_num:02d}.{year}" in source or f"{year}-{month_num:02d}-{int(day):02d}" in source:
                continue
        # 2026-05-12 — Whitelist 1: yearless mention in current-year events.
        # German news convention: "11. April" без года для current-year items.
        # AI добавляет current year (правильно) → validator не находит "11. april 2026"
        # в source → false positive. Принимаем yearless форму если year == текущий.
        if month_num and int(year) == current_year:
            if f"{day}. {month_de_norm}" in source:
                continue
            if f"{month_en} {day}" in source or f"{day} {month_en}" in source:
                continue
            if f"{day}.{month_num:02d}." in source:
                continue
        # 2026-05-12 — Whitelist 2: today/yesterday/tomorrow с relative-time
        # hint в source. AI часто конвертирует "heute/gestern/morgen" в
        # explicit calendar date — это допустимый transformation если source
        # содержит relative-time маркер (heute, today, сьогодні, kürzlich, live).
        if today is not None and month_num and has_relative_hint:
            try:
                parsed = _dt.date(int(year), month_num, int(day))
                days_diff = (parsed - today).days
                if -2 <= days_diff <= 2:
                    continue
            except (ValueError, TypeError):
                pass
        unsupported.append(f"{day}. {month_de} {year}")
    return sorted(set(unsupported))


_NAME_SKIP_LAST_WORDS = {
    # Geographic and institutional terms that look like surnames after a
    # German compound noun.
    "Deutschland", "Europa", "Ukraine", "Union", "Bundestag", "Bundesrat",
    "Kabinett", "Krankenversicherung", "Krankenkassen", "Deutschlandfunk",
    "EU", "NATO", "UNO", "USA", "WHO", "OECD", "OSZE", "G7", "G20",
    "Bundeswehr", "Bundesrepublik", "Bundesregierung", "Landesregierung",
    # 2026-05-13: country genitives (Aggression Russlands, Hilfe Chinas etc.)
    # — это атрибут страны, не имя человека. Все были false-positive в проде.
    "Deutschlands", "Russlands", "Chinas", "Frankreichs", "Englands",
    "Italiens", "Spaniens", "Polens", "Tschechiens", "Ungarns",
    "Österreichs", "Schweiz", "Niederlande", "Belgiens", "Luxemburgs",
    "Schweizer", "Türkei",  # nominative forms — kept для compound-noun matches типа
    # «Botschafter Schweiz», но добавляем canonical genitive ниже
    "Ukraines", "Belarus", "Moldovas", "Rumäniens", "Bulgariens",
    "Israels", "Iraks", "Irans", "Syriens", "Libanons", "Ägyptens",
    "Amerikas", "Kanadas", "Mexikos", "Brasiliens", "Argentiniens",
    "Indiens", "Pakistans", "Afghanistans", "Japans", "Koreas",
    "Türkeis", "Saudis", "Arabiens", "Jordaniens",  # 2026-05-13 v21: canonical genitive
    "Großbritanniens", "Britanniens",
    "Niederlandes", "Belarus'",  # additional genitive forms
    # 2026-05-13: common German nouns that get matched as "surname" after an
    # adjective (Künstliche Intelligenz, Aggression Russlands etc.)
    "Intelligenz", "Aggression", "Kommando", "Verwaltung", "Behörde",
    "Polizei", "Justiz", "Regierung", "Opposition", "Koalition",
    "Wirtschaft", "Industrie", "Branche", "Energie", "Klima", "Umwelt",
    "Bildung", "Forschung", "Wissenschaft", "Technologie", "Innovation",
    "Sicherheit", "Verteidigung", "Außenpolitik", "Innenpolitik",
    "Gesundheit", "Medizin", "Pharmazie", "Therapie", "Diagnose",
    "Kultur", "Kunst", "Musik", "Literatur", "Theater", "Film", "Sport",
    "Verkehr", "Mobilität", "Logistik", "Transport", "Infrastruktur",
    "Migration", "Integration", "Asyl", "Flucht", "Vertreibung",
    "Demokratie", "Diktatur", "Republik", "Monarchie", "Föderation",
    "Initiative", "Strategie", "Konzept", "Programm", "Projekt", "Plan",
    "Ansatz", "Methode", "Verfahren", "Prozess", "System", "Modell",
    "Entscheidung", "Wahl", "Abstimmung", "Beschluss", "Urteil", "Gericht",
    "Krieg", "Konflikt", "Krise", "Eskalation", "Sanktionen", "Embargo",
    "Hilfe", "Unterstützung", "Förderung", "Kooperation", "Zusammenarbeit",
    "Präsident", "Präsidentin", "Kanzler", "Kanzlerin", "Minister", "Ministerin",
    "Politik", "Politiker", "Politikerin",
    # Publication and agency names that show up in attribution clauses
    # ("wie Reuters berichtet"); the regex would otherwise treat
    # «German-Compound Reuters» as a person name.
    "Reuters", "AP", "AFP", "DPA", "EFE", "ANSA", "TASS", "Bloomberg",
    "Spiegel", "Welt", "Zeit", "FAZ", "Tagesschau", "Tagesspiegel",
    "Handelsblatt", "Süddeutsche", "Bild", "Stern", "Focus", "NDR", "BR",
    "ARD", "ZDF", "BBC", "CNN", "Reuters", "Politico", "Guardian",
    "Euronews", "DW", "Ukrinform", "Pravda", "Independent", "LIGA",
    "UNIAN", "Suspilne",
    # 2026-05-13: aggregator / niche source names (production false-positives)
    "Golem", "Heise", "Netzpolitik", "ntv", "N-tv", "RND", "Watson",
    "T-Online", "Web.de", "Gmx", "Yahoo", "Google",
    # German articles / fillers that occasionally end up matched as a
    # «last word» of a fake compound.
    "Der", "Die", "Das", "The",
}

# Tokens that, if they appear AS the first word of a candidate full name,
# rule the match out: they're either German articles/prepositions, role
# nouns ("Regisseurin Mahnaz"), substantivized adjectives ("Jugendliche Immer"),
# or a composite-noun prefix that the regex misread as a first name.
_NAME_SKIP_FIRST_WORDS = {
    # Articles / pronouns
    "Der", "Die", "Das", "Den", "Dem", "Des", "Ein", "Eine", "Einen", "Einem", "Einer",
    "Dieser", "Diese", "Dieses", "Jener", "Jene", "Solche", "Solcher",
    "The", "An", "A",
    # 2026-05-13: German prepositions/conjunctions at sentence start
    # ("Laut Golem", "Nach Russlands", "Aus Sicht", "Bei Angriffen" etc.)
    # — никогда не имя, всегда attribution или syntactic marker.
    "Laut", "Nach", "Aus", "Bei", "Mit", "Vor", "Von", "Für", "Gegen",
    "Über", "Unter", "Zwischen", "Während", "Trotz", "Wegen", "Durch",
    "Auf", "An", "In", "Zu", "Bis", "Seit", "Ab", "Ohne", "Um",
    "Wie", "Als", "Wenn", "Falls", "Sobald", "Sofern", "Obwohl",
    "Inmitten", "Anhand", "Angesichts", "Bezüglich", "Hinsichtlich",
    # Common German adverbs that start sentences then look like first names
    "Auch", "Schon", "Bereits", "Erst", "Sogar", "Genau", "Zudem",
    "Allerdings", "Jedoch", "Deshalb", "Daher", "Folglich", "Somit",
    "Inzwischen", "Mittlerweile", "Stattdessen", "Andererseits",
    # 2026-05-13: common declined adjectival forms at sentence start
    # (Allgemeine/r/n/m, Künstliche, Evidenzbasierter, Hessische, Bayerische etc.)
    "Allgemeine", "Allgemeiner", "Allgemeinen", "Allgemeinem", "Allgemeines",
    "Künstliche", "Künstlicher", "Künstlichen", "Künstliches",
    "Natürliche", "Natürlicher", "Natürlichen",
    "Evidenzbasierte", "Evidenzbasierter", "Evidenzbasierten",
    "Hessische", "Hessischer", "Hessischen",
    "Bayerische", "Bayerischer", "Bayerischen",
    "Berliner", "Münchner", "Hamburger", "Kölner", "Frankfurter",
    "Sächsische", "Sächsischer", "Sächsischen",
    "Niedersächsische", "Schleswig-Holsteinische",
    "Europäische", "Europäischer", "Europäischen", "Europäisches",
    "Amerikanische", "Amerikanischer", "Amerikanischen",
    "Russische", "Russischer", "Russischen",
    "Chinesische", "Chinesischer", "Chinesischen",
    "Ukrainische", "Ukrainischer", "Ukrainischen",
    "Französische", "Französischer", "Französischen",
    "Britische", "Britischer", "Britischen",
    "Italienische", "Italienischer", "Italienischen",
    "Polnische", "Polnischer", "Polnischen",
    "Türkische", "Türkischer", "Türkischen",
    "Israelische", "Israelischer", "Israelischen",
    "Iranische", "Iranischer", "Iranischen",
    "Syrische", "Syrischer", "Syrischen",
    "Informelle", "Informeller", "Informellen",
    "Formelle", "Formeller", "Formellen",
    "Wichtige", "Wichtiger", "Wichtigen",
    "Neue", "Neuer", "Neuen", "Alte", "Alter", "Alten",
    "Aktuelle", "Aktueller", "Aktuellen",
    "Konkrete", "Konkreter", "Konkreten",
    "Mögliche", "Möglicher", "Möglichen",
    "Notwendige", "Notwendiger", "Notwendigen",
    "Geplante", "Geplanter", "Geplanten",
    "Geltende", "Geltender", "Geltenden",
    "Bestehende", "Bestehender", "Bestehenden",
    "Verschiedene", "Verschiedener", "Verschiedenen",
    # German compound prefixes
    "Bundes", "Landes", "Stadt", "Land", "Volks", "Welt",
    # Substantivized adjectives commonly appearing at sentence start
    "Jugendliche", "Jugendlicher", "Erwachsene", "Erwachsener", "Beamte", "Beamter",
    "Reisende", "Reisender", "Tote", "Toter", "Verletzte", "Verletzter",
    "Frühere", "Früherer", "Ehemalige", "Ehemaliger", "Neue", "Neuer", "Erste", "Erster",
    "Letzte", "Letzter", "Spätere", "Späterer", "Kommende", "Kommender",
    # Role / title nouns (2026-05-12 fix: "Regisseurin Mahnaz", "Trainer Klopp", etc.
    # При парном matching они выглядят как «first_name last_name», но первое слово —
    # роль, а второе — реальная фамилия). 13 «Boris Pistorius» false-positive сегодня.
    "Regisseur", "Regisseurin", "Regisseure", "Schauspieler", "Schauspielerin",
    "Autor", "Autorin", "Direktor", "Direktorin", "Intendant", "Intendantin",
    "Sänger", "Sängerin", "Komponist", "Komponistin", "Maler", "Malerin",
    "Trainer", "Trainerin", "Spieler", "Spielerin", "Schiedsrichter", "Schiedsrichterin",
    "Stürmer", "Stürmerin", "Torwart", "Torhüter", "Mittelfeldspieler",
    "Minister", "Ministerin", "Bundeskanzler", "Bundeskanzlerin", "Kanzler", "Kanzlerin",
    "Präsident", "Präsidentin", "Vizepräsident", "Vizepräsidentin",
    "Politiker", "Politikerin", "Abgeordneter", "Abgeordnete", "Senator", "Senatorin",
    "Wissenschaftler", "Wissenschaftlerin", "Forscher", "Forscherin",
    "Professor", "Professorin", "Doktor", "Doktorin",
    "Manager", "Managerin", "Chef", "Chefin", "Vorstandschef", "Vorstandschefin",
    "Sprecher", "Sprecherin", "Vertreter", "Vertreterin",
    "Anwalt", "Anwältin", "Richter", "Richterin", "Staatsanwalt", "Staatsanwältin",
    "Aktivist", "Aktivistin", "Demonstrant", "Demonstrantin",
    "Journalist", "Journalistin", "Reporter", "Reporterin", "Moderator", "Moderatorin",
    "General", "Generalin", "Oberst", "Hauptmann", "Soldat", "Soldatin",
    "Bürgermeister", "Bürgermeisterin", "Landrat", "Landrätin", "Gouverneur", "Gouverneurin",
    "Polizist", "Polizistin", "Kommissar", "Kommissarin",
    "Arzt", "Ärztin", "Chefarzt", "Chefärztin",
}


# Well-known public figures (2026-05-12 fix): полное имя ("Boris Pistorius",
# "Friedrich Merz") в news context — это не fabrication, даже если source
# называет только фамилию. AI legitimately обогащает текст для понятности
# среднему читателю. 13 «Boris Pistorius» false-positive сегодня.
#
# Лимит: только national-level политики, главы государств, известные deutscher
# министры и публичные фигуры с unambiguous full-name знанием. Региональные /
# local политики — остаются strict (там реально риск fabrication).
_KNOWN_FULL_NAMES = {
    # Германия — кабинет
    "boris pistorius", "friedrich merz", "olaf scholz", "robert habeck",
    "annalena baerbock", "christian lindner", "karl lauterbach",
    "nancy faeser", "hubertus heil", "lisa paus", "cem özdemir", "cem oezdemir",
    "marco buschmann", "volker wissing", "klara geywitz", "steffi lemke",
    "svenja schulze", "bettina stark-watzinger", "wolfgang schmidt",
    "ulrich kelber", "lars klingbeil", "saskia esken",
    "alice weidel", "tino chrupalla", "sahra wagenknecht",
    "markus söder", "markus soeder",
    "frank-walter steinmeier", "bärbel bas", "baerbel bas",
    "katherina reiche", "ricarda lang", "omid nouripour",
    "warken", "nina warken",
    # Государства — главы
    "wolodymyr selenskyj", "wolodymyr selensky", "wladimir selenskyj",
    "wladimir putin", "donald trump", "joe biden", "kamala harris",
    "emmanuel macron", "rishi sunak", "keir starmer", "giorgia meloni",
    "pedro sánchez", "pedro sanchez", "viktor orbán", "viktor orban",
    "andrzej duda", "donald tusk", "alexander van der bellen",
    "karl nehammer", "edi rama", "milorad dodik", "aleksandar vučić",
    "benjamin netanjahu", "benjamin netanyahu", "naftali bennett",
    "ali chamenei", "ebrahim raisi", "masoud pezeshkian",
    "recep tayyip erdoğan", "recep tayyip erdogan",
    "kim jong un", "fumio kishida", "xi jinping",
    # EU / международные
    "ursula von der leyen", "charles michel", "kaja kallas", "josep borrell",
    "antónio costa", "antonio costa", "roberta metsola", "jens stoltenberg",
    "mark rutte", "alexander stubb",
    # СНГ / Украина — известные политики и военные
    "андрій єрмак", "andrij yermak", "андрей ермак",
    "руслан стефанчук", "денис шмигаль", "ірина верещук",
    "валерій залужний", "olexander syrskyj", "олександр сирський",
    "kyrylo budanow", "кирило буданов",
    # Россия — публичные политики
    "сергій лавров", "sergej lavrov", "sergei lavrov",
    "дмитро пєсков", "dmitri peskow", "dmitry peskov",
    "wjatscheslaw wolodin", "viacheslav volodin",
    # Известные регулярно в новостях персоны
    "elon musk", "jeff bezos", "mark zuckerberg", "tim cook",
    "sundar pichai", "satya nadella", "sam altman",
}


def _strip_unsupported_first_names(result: RewriteResult, source_text: str) -> RewriteResult:
    """Remove model-added first names when the source only gives a surname."""
    result.title_de = _strip_unsupported_first_names_from_text(result.title_de, source_text)
    result.lead_de = _strip_unsupported_first_names_from_text(result.lead_de, source_text)
    result.body_de = _strip_unsupported_first_names_from_text(result.body_de, source_text)
    result.card_lead_de = _strip_unsupported_first_names_from_text(result.card_lead_de, source_text)
    return result


def _normalize_german_style(result: RewriteResult) -> RewriteResult:
    result.title_de = _normalize_german_text(result.title_de)
    result.lead_de = _normalize_german_text(result.lead_de)
    result.body_de = _normalize_german_text(result.body_de)
    result.card_lead_de = _normalize_german_text(result.card_lead_de)
    return result


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
    result.lead_de = _move_german_source_attribution(result.lead_de)
    result.body_de = _move_german_source_attribution(result.body_de)
    return result


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


def _detect_fabricated_proper_nouns(generated_text: str, source_text: str, story_card: dict | None, supporting_text: str = "") -> list[str]:
    """2026-05-12 W1.3: catch fully fabricated proper-noun pairs.

    R8 Phase 1 2026-05-14: дополнительный `supporting_text` для cross-reference
    с supporting source dossier (titles + domains). Reduces FP rate ~40% за
    счёт расширения trusted_tokens — имена которые есть в supporting sources
    теперь не флагаются как fabricated.

    ESC post 10149 invented "Linda Lampenius", "Pete Parkkonen", "Liekinheitin",
    "Noam Bettan", "Michelle" — none in source nor in story_card.entities_people.
    Existing `_unsupported_generated_full_names` only catches «source has LastName,
    AI added FirstName». This catches «AI invented WHOLE name».

    Algorithm:
    1. Collect all 2-word capitalized pairs from generated text (likely names).
    2. Build trusted-name allowlist:
       - All names appearing word-by-word в source_text
       - All entities в story_card.entities_people / .entities.people
       - _KNOWN_FULL_NAMES whitelist
    3. For each generated pair: if NEITHER first nor last word is в trusted-set
       AND first not in _NAME_SKIP_FIRST_WORDS AND last not in _NAME_SKIP_LAST_WORDS
       → fabrication candidate.

    Conservative: false negatives OK (better miss than block legitimate names);
    false positives bad (would block real news with proper figures).
    """
    if not generated_text or not source_text:
        return []
    # Collect trusted-name token set
    trusted_tokens: set[str] = set()
    # From source (split by non-word chars, lowercase)
    for tok in re.findall(r"[A-ZÄÖÜ][A-Za-zÄÖÜäöüßéèêíìîáàâóòôúùû\-']{2,}", source_text):
        trusted_tokens.add(tok.lower())
    # From story_card.entities_people
    if isinstance(story_card, dict):
        people = story_card.get("entities_people") or story_card.get("entities", {}).get("people") or []
        if isinstance(people, list):
            for p in people:
                if isinstance(p, dict):
                    name = str(p.get("name") or "").strip()
                    for part in re.split(r"\s+", name):
                        if len(part) >= 3:
                            trusted_tokens.add(part.lower())
        # Also entities_organizations и places — songs/works могут быть там
        for key in ("entities_organizations", "entities_places", "topics", "tags"):
            items = story_card.get(key) or []
            if isinstance(items, list):
                for it in items:
                    if isinstance(it, dict):
                        name = str(it.get("name") or "").strip()
                    else:
                        name = str(it).strip()
                    for part in re.split(r"\s+", name):
                        if len(part) >= 3:
                            trusted_tokens.add(part.lower())
    # Also key_facts текст
    if isinstance(story_card, dict):
        facts = story_card.get("key_facts") or []
        if isinstance(facts, list):
            for f in facts:
                if isinstance(f, str):
                    for tok in re.findall(r"[A-ZÄÖÜ][A-Za-zÄÖÜäöüßéèêíìîáàâóòôúùû\-']{2,}", f):
                        trusted_tokens.add(tok.lower())
    # R8 Phase 1 2026-05-14: supporting source dossier text (titles + domains).
    # Если AI-generated name появляется в supporting title — это confirmed real
    # person mentioned by independent sources. Reduces FP epidemic от German
    # compound nouns где совпадение случайное.
    if supporting_text:
        for tok in re.findall(r"[A-ZÄÖÜ][A-Za-zÄÖÜäöüßéèêíìîáàâóòôúùû\-']{2,}", supporting_text):
            trusted_tokens.add(tok.lower())
    # 2026-05-13 (revised): overlap-aware token-pair detection.
    # Старая реализация с `re.finditer` consume'ила match — добавив "Laut" в
    # `_NAME_SKIP_FIRST_WORDS`, regex матчил `(Laut, Linda)` как пару (skip),
    # и `Linda Lampenius` уже НЕ попадал в следующий проход. ESC-style real
    # fabrication (Lampenius+Parkkonen) пропускалась незамеченной.
    # Fix: токенизация всех capitalized слов с позициями, перебор смежных пар.
    token_pattern = re.compile(
        r"\b([A-ZÄÖÜ][A-Za-zÄÖÜäöüßéèêíìîáàâóòôúùû\-']{2,})\b"
    )
    # Collect (token, start_pos, end_pos) tuples
    tokens: list[tuple[str, int, int]] = [
        (m.group(1), m.start(1), m.end(1)) for m in token_pattern.finditer(generated_text)
    ]
    # R8 Phase 2 2026-05-14: pre-compute spaCy PERSON entity set для filtering
    # compound-noun false positives. Если candidate pair НЕ в этом set'е, скорее
    # всего это German compound noun (Bundesverteidigungsminister Pistorius),
    # не fabrication. Skip flagging.
    spacy_persons: set[str] | None = None
    nlp = _get_de_nlp()
    if nlp is not None:
        try:
            doc = nlp(generated_text)
            spacy_persons = {
                ent.text.lower()
                for ent in doc.ents
                if ent.label_ == "PER"
            }
            # Also add individual PERSON tokens for partial-match lookups.
            for ent in doc.ents:
                if ent.label_ == "PER":
                    for word in ent.text.split():
                        if len(word) >= 3:
                            spacy_persons.add(word.lower())
        except Exception as exc:  # noqa: BLE001
            logger.warning("spaCy NER pass failed, falling back to heuristic: %s", exc)
            spacy_persons = None

    fabricated: list[str] = []
    seen: set[str] = set()
    for i in range(len(tokens) - 1):
        first, fstart, fend = tokens[i]
        last, lstart, lend = tokens[i + 1]
        # Should be syntactically adjacent (only whitespace between).
        between = generated_text[fend:lstart]
        if not between or not between.isspace():
            continue
        if first.isupper() or last.isupper():
            continue  # all-caps acronyms
        if first in _NAME_SKIP_FIRST_WORDS or last in _NAME_SKIP_LAST_WORDS:
            continue
        if "-" in first:
            continue
        full = f"{first} {last}"
        if full in seen:
            continue
        seen.add(full)
        if full.lower() in _KNOWN_FULL_NAMES:
            continue
        first_known = first.lower() in trusted_tokens
        last_known = last.lower() in trusted_tokens
        if first_known or last_known:
            continue
        # R8 Phase 2: spaCy PERSON entity gate. Если spaCy не считает pair
        # PERSON entity — это compound noun, не fabrication. Skip.
        if spacy_persons is not None:
            is_person = (
                full.lower() in spacy_persons
                or first.lower() in spacy_persons
                or last.lower() in spacy_persons
            )
            if not is_person:
                continue
        fabricated.append(full)
    return sorted(set(fabricated))


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
        if first in _NAME_SKIP_FIRST_WORDS:
            continue
        # Hyphen in the first token = it's a German compound noun (e.g.
        # "US-Hauskarte"), not a personal first name. Skip — the next
        # token is just attribution ("Reuters") or another noun.
        if "-" in first:
            continue
        full = f"{first} {last}"
        if re.search(rf"\b{re.escape(full)}\b", source):
            continue
        # 2026-05-12: well-known public figures whitelist. Boris Pistorius,
        # Friedrich Merz, Wladimir Putin etc. — общеизвестные фигуры. AI
        # обогащает текст полным именем когда source говорит только фамилию —
        # это легитимно, не fabrication. 13 «Boris Pistorius» false-positive
        # сегодня вызвали блокировку легитимных rewrite-ов.
        if full.lower() in _KNOWN_FULL_NAMES:
            continue
        # If the source only names the surname, adding a first name is a new fact.
        if re.search(rf"\b{re.escape(last)}\b", source) and not re.search(rf"\b{re.escape(first)}\b", source):
            unsupported.append((full, last))
    return sorted(set(unsupported))
