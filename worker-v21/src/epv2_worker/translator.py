"""
EuroPulse AutoPilot v2.1 — Translator
Translates German master → Ukrainian + English.
"""
from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass

from openai import AsyncOpenAI

from .openai_compat import completion_debug, completion_text, completion_total_tokens, reasoning_extra_body

logger = logging.getLogger(__name__)


@dataclass
class TranslationResult:
    title: str = ""
    lead: str = ""
    body: str = ""
    # Carded lead-magnet — übersetzte Variante des deutschen card_lead.
    # Genau ein vollständig geschlossener Satz, sprachspezifisches Längenziel
    # (UK: 95–115 Zeichen, EN: 110–130 Zeichen). Wenn die KI das Kontrakt
    # nicht einhält, bleibt das Feld leer und das Mu-Plugin fällt auf den
    # bestehenden post_excerpt-Render zurück.
    card_lead: str = ""
    success: bool = False
    error: str = ""
    provider: str = ""
    model: str = ""
    tokens: int = 0
    # Anti-plagiarism gate (architecture phase 3) — translation is also
    # checked against the primary-source text in its source language.
    uniqueness_pct: float = 100.0
    uniqueness_passed: bool = True
    uniqueness_reason: str = ""


_SYSTEM_PROMPT_TEMPLATE = """Du bist ein professioneller Übersetzer für die Nachrichtenplattform EuroPulse.today.
Übersetze den deutschen Nachrichtenartikel vollständig und präzise ins {target_lang}.
Erhalte journalistische Genauigkeit und Faktenlage — keine Auslassungen, keine eigenen Ergänzungen.

STIMME UND STIL (2026-05-12, Pflicht):
Schreibe wie ein Top-Reporter (Spiegel / Guardian / NYT live) seinen Leser ansprechen würde — lebendig, klar, alltagsnah, mit aktiven Verben und konkreten Bildern. Nicht behördlich, nicht PR-haft, nicht boulevardesk.
- Wenn das deutsche Original im Behörden-/Pressemitteilungston steht (Nominalstil, Endlos-Substantivketten, leere Floskeln) — beim Übersetzen den TON umschreiben: aktive Verben, kurze Sätze, plain language. Faktenlage 1:1, aber Sprache wird zur Zielsprache lebendig gemacht.
- Bürokratische Ketten («Verwaltungsvorschriften», «Ausführungsverordnungen», «Modernisierungsgesetze») in der Zielsprache mit einem klaren Wort wiedergeben oder kurz erklären, NICHT 1:1 als «адміністративні норми», «виконавчі постанови», «закони про модернізацію» kopieren — das klingt in UK/EN noch behördlicher als im Original.
- Leere Pressestellen-Zitate («це має ще дійти до громадян», «нові закони ще мають проявити свою дію», «we are monitoring the situation») in der Übersetzung WEGLASSEN — keine wörtliche Übersetzung von Floskeln, lieber Faktsatz ohne Zitat.
- Satzlänge meist 12–20 Wörter. Schachtelsätze auflösen. Nominalstil zu Aktivverben drehen («скасування 519 норм» → «уряд скасував 519 норм»).

PFLICHTREGELN FÜR DIE ÜBERSETZUNG:
- Quellenangabe-Formeln müssen korrekt übertragen werden, aber nicht mechanisch am Anfang jedes Absatzes wiederholt werden.
- Wenn die Quelle nicht exklusiv ist, beginne Lead und ersten Absatz mit der Nachricht selbst; setze die Quellenformel erst danach, z. B. „..., повідомляє [джерело]".
- Abkürzungen: beim ersten Auftreten die volle Form in der Zielsprache nennen und die Abkürzung in Klammern behalten, z. B. „European Union (EU)" / „Європейський Союз (ЄС)".
- Exklusive Zuschreibungen und Zitate wörtlich und vollständig übertragen.
- Namen, Daten, Zahlen und Eigennamen unverändert übernehmen. Personen nicht sofort nur auf nackte Nachnamen reduzieren: bei erster Erwähnung Rolle/Funktion + Name oder Nachname, sofern die Quelle die Rolle nennt.
- Erfinde keine Vornamen, Funktionen oder Rollen aus Allgemeinwissen. Wenn der deutsche Master nur einen Nachnamen nennt, schreibt die Übersetzung ebenfalls nur diesen Nachnamen; keine Zusätze wie „Bavarian Minister-President", „Prime Minister" oder ähnliche Rollen.

- ENTITÄTS-KONSISTENZ ZWISCHEN TITEL/LEAD/BODY (Pflicht-Pflicht, 2026-05-12):
  Jeder Eigenname / Personenname im TITEL der Übersetzung MUSS im Body der gleichen Übersetzung in transkribierter Form vorkommen, und sein deutsches Original MUSS im deutschen Master stehen.
  Es ist STRIKT VERBOTEN, im Titel einen Namen zu setzen, der im DE-Master nicht erwähnt wird — egal wie bekannt oder kontextuell passend dieser Name erscheinen mag.
  Beispiel: Wenn DE-Master "Söder, Wüst, Rhein" nennt — UK-Titel transkribiert exakt diese drei Namen (Зедер, Вуст, Райн). EN-Titel behält Söder, Wüst, Rhein. Keine Substitution durch andere bekannte Politikernamen.
  Vor dem Senden: prüfe innerlich, dass jeder Name im Titel auch im Body steht. Wenn nicht — Titel umformulieren.
- Die gesamte Ausgabe muss in der Zielsprache sein. Für English sind ukrainische oder russische Wörter wie „повідомляє", „за даними" oder „заявив" verboten.
- Keine inhaltlichen Zusammenfassungen oder Auslassungen.
- Überschriften natürlich formulieren, keine wortwörtlichen deutschen Komposita.
- Für Ukrainisch: „Ticker" im Nachrichtenkontext nicht als holprige „стрічка" übersetzen. Nutze „хроніка", „оновлення" oder „онлайн-оновлення"; „Nahost-Ticker" → „Хроніка подій на Близькому Сході" oder „Оновлення щодо Близького Сходу".
- Für Ukrainisch: „gesetzliche Krankenkassen" immer als „каси обов’язкового медичного страхування" übersetzen. Niemals „законодавчі фонди", „державні страхові фонди", „фармацевтичний сектор" oder ähnliche Kalques.
- Für Ukrainisch: keine Kanzleisprache und keine deutschen Kalques. Vermeide Formeln wie „вбачає потребу", „з огляду на", „у повідомленні не деталізовано", „подальші парламентські консультації", „органи, відповідальні за законодавство". Schreibe stattdessen lebendig und präzise: „вважає, що пакет треба змінити", „під час розгляду в парламенті", „деталей поки немає".
- Für Ukrainisch — Genus-Übereinstimmung Pflicht: deutsche Substantive übernehmen ihr Geschlecht NICHT auf das ukrainische Wort. „die Parade" (DE: feminin) → „парад" (UK: maskulin). Adjektive, Verben und Pronomen müssen sich nach dem ukrainischen Geschlecht richten, nicht nach dem deutschen. Korrekt: „військовий парад", „пройшов парад", „цей парад"; FALSCH: „військова парад", „пройшла парад", „ця парад". Das Gleiche gilt für: „der Bericht" → „звіт" (m, nicht f), „der Saldo" → „баланс" (m), „der Abend" → „вечір" (m), „die Krise" → „криза" (f, übereinstimmt), „das Unternehmen" → „підприємство" (n, übereinstimmt), „der Beschluss" → „рішення" (n, NICHT m), „die Sitzung" → „засідання" (n, NICHT f).
- Titel, Lead und erster Absatz müssen unterschiedliche Aufgaben erfüllen: Titel meldet die Nachricht, Lead erklärt die Relevanz in 1–2 Sätzen, der erste Absatz führt mit neuen Details weiter. Nicht alle drei mit derselben Quellenformel oder denselben ersten Wörtern beginnen.
- Der erste Absatz darf den Lead nicht nacherzählen. Er muss konkretisieren: wer betroffen ist, was sich ändert, welche offenen Punkte es gibt oder was als Nächstes passiert.
- ANTI-FÜLLTEXT — diese Phrasen sind in der Übersetzung VERBOTEN, auch wenn das deutsche Original sie enthält (dann beim Übersetzen weglassen, nicht hinzufügen):
  • UK: «можливі наслідки», «це рішення може вплинути», «офіційне підтвердження поки що відсутнє», «подальші деталі поки не відомі», «це піднімає питання», «залишається спостерігати», «експерти вбачають у цьому», «це свідчить про…», «це підкреслює…», «це відображає…», «це вказує на…», «це демонструє…», «зростаюче занепокоєння», «зростаючу стурбованість», «викликає занепокоєння», «у зв'язку з цим», «з огляду на це».
  • EN: «possible consequences», «could affect», «official confirmation is still pending», «further details are not yet known», «this raises questions», «it remains to be seen», «observers see this as», «this could indicate…», «this reflects…», «this underscores growing concerns», «growing concerns about», «highlights mounting tensions», «in light of this», «against this backdrop» (если без конкретного факта).
  Statt solcher leeren Sätze: kürzere Übersetzung. Lieber 150 dichte Wörter als 350 mit Wassertext.
- Wenn das deutsche Original einen Absatz hat, der nur aus solchen Filler-Sätzen besteht — diesen Absatz in der Übersetzung WEGLASSEN. Lückenhafte Quelle bleibt lückenhafte Quelle, in jeder Sprache.
- ОСОБОЕ ПРАВИЛО для UK/EN финальных абзацев: VERBOTEN — последний абзац не должен быть meta-комментарием типа «Це свідчить про зростаюче занепокоєння…» / «This reflects growing concerns…». Финальный абзац ДОЛЖЕН содержать конкретный факт: следующий шаг с датой, реакцию с именем-функцией-цитатой, исторический контекст с числами. Если такого факта нет — финальный абзац ОПУСТИТЬ.
- BEWAHREN ABLEHNUNG / REJECTION — wenn DE master strong refusal формулировку содержит,
  переводить эту силу 1:1, не softeneть. Konkret:
  Wenn DE sagt «Kyiv lehnt Treffen in Moskau ab — Hauptstadt des Aggressorstaats»,
  übertragen wir die ablehnende Stärke 1:1 mit gleicher Wortwahl. NICHT abschwächen zu
  einer hypothetischen Möglichkeit, NICHT die Akteure umbenennen oder andere Personen
  ins Subjekt setzen — nur den Inhalt des DE-Masters wiedergeben.
- KONKRETE ZAHLEN — KEINE NEUEN ERFINDUNGEN beim Übersetzen: wenn DE master
  spezifische Zahlen enthält (Preise, Index-Stände, Prozent, Gehälter),
  übertrage die GLEICHEN Zahlen 1:1. NIE eigene Zahlen hinzufügen, die nicht
  im DE master stehen. Wenn DE allgemein formuliert («mehrere tausend Euro»),
  übersetze ebenfalls allgemein («кілька тисяч євро» / «several thousand
  euros») — niemals durch konkrete Erfindungen ersetzen.
- Bei der Übersetzung gilt: DE master = ein-zu-eins Vorlage für Fakten.
  Translator hat keine Quelle ausser dem DE master + story_card.key_facts.
  Wenn UK/EN spezifische Zahl enthält, die weder im DE noch in key_facts
  steht — diese Zahl IST eine Halluzination und muss entfernt werden.
- KEINE ERFUNDENE QUELLENATTRIBUTION beim Übersetzen: nur Publisher-Namen
  übertragen, die im DE master tatsächlich genannt sind. NIEMALS neue
  Outlets wie „Reuters", „BBC", „Wall Street Journal", „Bild am Sonntag",
  „Tagesspiegel", „Rheinische Post" hinzufügen, auch nicht „zur stilistischen
  Variation" oder weil sie zur Zielsprache passen. Wenn DE master nur eine
  Quelle nennt — die Übersetzung erwähnt ebenfalls nur diese eine Quelle.
- KEINE ERFUNDENEN SPRECHER: keine Personen mit direkten Zitaten einführen,
  deren Name nicht im DE master vorkommt. Selbst wenn die Quelle einen
  „Experten" / „Analysten" / „Sprecher" anonym erwähnt — KEINEN Namen wie
  „Dr. Müller" oder „Robert Edwards" erfinden, um die Translation natürlicher
  zu machen.

ВАЖЛИВО — РОСІЯ-УКРАЇНА — обов'язкова редакційна лінія (БЕЗ винятків):
EuroPulse висвітлює повномасштабну агресивну війну Росії проти України з лютого 2022 року.
Росія — країна-агресор. Це не точка зору, не одна з версій, а зафіксований факт.

Обов'язкова лексика для перекладу на українську:
• «російська агресія», «російська збройна агресія», «повномасштабне вторгнення Росії в Україну»
• «російські окупанти», «окуповані території», «російська окупація»
• «війна Росії проти України», «російсько-українська війна» (НЕ «конфлікт»)
• «Володимир Путін», «голова Кремля Путін», «диктатор Путін» — не «лідер Росії» в нейтральному значенні
• «воєнні злочини», «терористичні удари», «російські обстріли», «російські ракетні удари»

ЗАБОРОНЕНО — ці формулювання НІКОЛИ не використовувати:
• «напружені відносини між двома країнами» — між Україною та Росією йде війна, а не напружені відносини
• «конфлікт» без вказівки агресора — це створює хибну симетрію
• «суперечка», «розбіжності», «двостороння криза», «дипломатична напруга»
• «ескалація» як описова характеристика — Росія ескалює, не «ситуація ескалує»
• «обидві сторони», «обидві країни» як рівноцінні актори — це фальшива еквідистанція
• «нібито», «можливо», «за українськими даними» коли Росія сама визнає
• Спекулятивне зм'якшення на кшталт «це може свідчити про напруження», «можливо вказує на», «це означає що відносини…» — ЗАБОРОНЕНО

Для перекладу на англійську — те саме:
• «Russia's war of aggression against Ukraine», «Russian invasion», «Russian occupation»
• «Russian occupiers», «occupied territories», «war crimes», «Russian missile strikes»
• Vladimir Putin, NEVER soft references like «Russian leader» in war contexts
• FORBIDDEN: «tensions between the two countries», «conflict» (without naming aggressor),
  «both sides», «escalation» (as state, not action), «could indicate strained relations»

Якщо німецький майстер містить нейтралізуючу формулу — ВИПРАВЛЯТИ при перекладі, не копіювати.
Якщо першоджерело подає «Konflikt» — у перекладі писати «російська війна проти України».
Спекулятивні фрази «це може означати», «можливо», «імовірно» в будь-якому військовому /
політичному контексті щодо Росії — заборонено. Тільки конкретні факти з джерела, без
зм'якшувальної інтерпретації.

PFLICHTFELD card_lead — Karten-Lead-Magnet für die Startseite:
- GENAU EIN vollständiger geschlossener Satz in der Zielsprache.
- Längenziel: für Ukrainisch 95–115 Zeichen, für Englisch 110–130 Zeichen.
- Selbstständige Aussage — der Leser versteht die Geschichte ohne den Artikel zu öffnen, fühlt aber den Drang weiterzulesen.
- KEINE Abkürzungen mit Punkt im Inneren (kein „8. Mai", kein „z. B.", kein „St. Petersburg" — ausschreiben oder umformulieren).
- KEINE drei Punkte am Ende, KEINE offenen Halbsätze, KEIN Ende auf Präposition / Konjunktion / Artikel / Hilfsverb.
- Endet mit klassischem Punkt, Frage- oder Ausrufezeichen.
- NICHT identisch zum lead, NICHT identisch zum title.
- Eigennamen vollständig (Vor- und Nachname, oder Funktion + Nachname) — niemals abgeschnitten.
- KEINE Quellenangabe im card_lead — verboten:
  • Ukrainisch: „за повідомленням X", „як повідомляє X", „повідомляє X", „за даними X", „пише X", „інформує X", „за словами X", „джерело пише".
  • Englisch: „according to X", „as X reports", „X reports", „X says", „per X", „sources say".
  Die Quellenangabe gehört in den Body-Text, nicht in den Karten-Hook.
  Der card_lead trägt die Nachricht selbst, nicht die Tatsache der Meldung.

Ausgabe ausschließlich als JSON: {{"title":"...","lead":"...","card_lead":"...","body":"..."}}"""


async def translate_from_german(
    title_de: str,
    lead_de: str,
    body_de: str,
    target_lang: str,   # "Ukrainian" or "English"
    openai_api_key: str,
    deepseek_api_key: str = "",
    provider_order: list[tuple[str, str, str]] | None = None,
    card_lead_de: str = "",
    story_card: dict | None = None,
) -> TranslationResult:
    system = _SYSTEM_PROMPT_TEMPLATE.format(target_lang=target_lang)
    card_lead_block = (
        f"\n\nKARTEN-LEAD (DE) — bitte als card_lead in {target_lang} übertragen:\n{card_lead_de}"
        if card_lead_de else ""
    )
    story_block = _format_story_card_for_translator(story_card) if story_card else ""
    user = (
        f"TITEL (DE):\n{title_de}\n\n"
        f"TEASER (DE):\n{lead_de}{card_lead_block}\n\n"
        f"ARTIKEL (DE):\n{body_de[:3000]}"
        f"{story_block}"
    )
    source_text = f"{title_de}\n{lead_de}\n{body_de}"

    candidates = provider_order or [
        ("openai", openai_api_key, "gpt-4o-mini"),
        ("deepseek", deepseek_api_key, "deepseek-chat"),
    ]
    for provider, api_key, model in candidates:
        if not api_key:
            continue
        result = await _call(user, system, api_key, provider, model, target_lang, source_text)
        if result.success:
            result.provider = provider
            result.model = model or ("deepseek-chat" if provider == "deepseek" else "gpt-4o-mini")
            _annotate_translation_uniqueness(result, source_text=source_text, target_lang=target_lang)
            return result

    return TranslationResult(error="All providers failed")


def _format_story_card_for_translator(card: dict) -> str:
    """Inject the upfront semantic context (entities, geography, key facts,
    editorial verdict) into the translator user prompt as INVARIANT
    constraints. The translator must respect these invariants regardless of
    how the DE master phrased them — names spelled the same way, geography
    not invented, refusal/aggressor framing preserved 1:1.
    """
    if not isinstance(card, dict) or not card:
        return ""
    parts: list[str] = ["\n\n--- SEMANTISCHE INVARIANTEN (Story Card, source-of-truth) ---"]
    geo = card.get("geography") or {}
    if isinstance(geo, dict):
        country = str(geo.get("primary_country") or "").strip()
        region = str(geo.get("primary_region") or "").strip()
        if country or region:
            geo_parts = [p for p in [country, region] if p]
            parts.append("Geografie: " + " / ".join(geo_parts))
    people = card.get("entities_people") or []
    if isinstance(people, list) and people:
        names = []
        for p in people[:8]:
            if not isinstance(p, dict):
                continue
            name = str(p.get("name") or "").strip()
            role = str(p.get("role") or "").strip()
            if not name:
                continue
            names.append(f"{name} ({role})" if role else name)
        if names:
            parts.append("Personen + Rollen (Schreibweise & Funktion einheitlich übernehmen): " + "; ".join(names))
    orgs = card.get("entities_organizations") or []
    if isinstance(orgs, list) and orgs:
        org_names = [str(o.get("name") or "").strip() for o in orgs[:6] if isinstance(o, dict) and o.get("name")]
        if org_names:
            parts.append("Organisationen: " + ", ".join(org_names))
    places = card.get("entities_places") or []
    if isinstance(places, list) and places:
        place_names = [str(pl).strip() for pl in places[:6] if str(pl).strip()]
        if place_names:
            parts.append("Orte: " + ", ".join(place_names))
    facts = card.get("key_facts") or []
    if isinstance(facts, list) and facts:
        fact_lines = [f"  - {str(f).strip()}" for f in facts[:6] if str(f).strip()]
        if fact_lines:
            parts.append("Schlüsselfakten (müssen in der Übersetzung erhalten bleiben):\n" + "\n".join(fact_lines))
    cat = card.get("category") or {}
    if isinstance(cat, dict):
        primary = str(cat.get("primary") or "").strip()
        if primary:
            parts.append(f"Rubrik (Tonfall-Anker): {primary}")
    editorial = str(card.get("editorial_match") or "").strip()
    if editorial:
        editorial_reason = str(card.get("editorial_reason") or "").strip()
        ed_line = f"Redaktionelle Einordnung: {editorial}"
        if editorial_reason:
            # Pass the rationale so translator знает WHY editor classified
            # the story this way — same context the rewriter receives.
            # Без этого UK/EN translator может смягчить framing если не
            # понимает почему DE master jagged hard line ("Aggressor",
            # "rejected", "occupied territory").
            ed_line += f" — обоснование: {editorial_reason}"
        ed_line += (
            ". Der Tonfall der Übersetzung muss diese Linie 1:1 wiedergeben "
            "(insbesondere Aggressor-Framing bei Russland/Ukraine, "
            "Ablehnung/Refusal nicht weichspülen, Quellenpositionen nicht abschwächen)."
        )
        parts.append(ed_line)
    parts.append(
        'Diese Invarianten überschreiben jede tonale Auslegung. Wenn der DE-Master eine Tatsache '
        'benennt (z. B. „Aggressor", „Ablehnung", konkrete Funktion einer Person), muss die '
        'Übersetzung dieselbe Tatsache mit derselben Schärfe tragen.'
    )
    return "\n".join(parts)


def _annotate_translation_uniqueness(
    result: TranslationResult,
    *,
    source_text: str,
    target_lang: str,
) -> None:
    """Anti-plagiarism gate on the translated lead+body.

    The DE master is itself a rewrite (already plagiarism-checked). What
    we want here is to make sure the translator did not paraphrase too
    closely *within* the target language — i.e. the UK / EN text uses
    its own register and is not a literal de→target word substitution.
    Our trigram check against the DE master would always pass because
    of the language switch, so we tag with target-language stopwords
    against the DE source for sanity (≥80%) and treat anything below
    as a soft warning, not a hard failure.
    """
    try:
        from .plagiarism import check_uniqueness
    except Exception:  # pragma: no cover
        return
    generated = "\n".join(filter(None, [result.lead or "", result.body or ""])).strip()
    if not generated or not source_text:
        return
    target = target_lang.lower()
    if target.startswith("ukrain"):
        lang_code = "uk"
    elif target.startswith("english"):
        lang_code = "en"
    else:
        lang_code = "de"
    verdict = check_uniqueness(
        generated_text=generated,
        source_text=source_text,
        language=lang_code,
        named_entities=[],
    )
    result.uniqueness_pct = round(verdict.uniqueness_pct, 1)
    result.uniqueness_passed = verdict.passed
    result.uniqueness_reason = verdict.reason


_CARD_LEAD_LIMITS = {
    # Долгие имена (когда target_lang == "ukrainian"/"english").
    "ukrain": (50, 180),
    "english": (50, 200),
    # Стандартные ISO-коды («uk», «en») — startswith проверка иначе
    # falls through на общий (50, 200) и UK получает английский max.
    "uk": (50, 180),
    "en": (50, 200),
}

# Source-attribution patterns по всем языкам. card_lead — чистый news hook,
# атрибуция источника живёт в теле статьи.
_TRANSLATED_CARD_LEAD_SOURCE_ATTRIBUTION_RE = re.compile(
    r"(?ix)"
    r"(?:^|[\s,;—–-])("
    # English
    r"according\s+to\s+[A-Z]"
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
    # German (in case translator left it in)
    r"|wie\s+[A-ZÄÖÜ][\w\.\-]+\s+(?:berichtet|meldet|mitteilt|schreibt)"
    r"|nach\s+angaben\s+(?:von|der|des)\s+[A-ZÄÖÜ]"
    r"|laut\s+[A-ZÄÖÜ][\w\.\-]+"
    r"|[A-ZÄÖÜ][\w\.\-]+\s+zufolge"
    r")"
)


def _sanitize_translated_card_lead(text: str, *, target_lang: str) -> str:
    """Validate the translated card_lead. Returns "" on contract failure
    so the mu-plugin renderer falls back to the legacy excerpt path."""
    if not text:
        return ""
    cleaned = re.sub(r"<[^>]+>", " ", text)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    cleaned = cleaned.strip("\"'`«»“”„")
    if not cleaned:
        return ""
    target_key = target_lang.lower()
    min_len, max_len = (50, 200)
    for prefix, limits in _CARD_LEAD_LIMITS.items():
        if target_key.startswith(prefix):
            min_len, max_len = limits
            break
    length = len(cleaned)
    if length < min_len or length > max_len:
        return ""
    if cleaned.endswith("…") or cleaned.endswith("..."):
        return ""
    if cleaned[-1] not in ".!?":
        return ""
    inner = cleaned[:-1]
    # Truncation guards: число/abbrev-точка В КОНЦЕ → trunc'нуто. Раньше
    # любое \b\d+\.\s в середине роняло валидные lead'ы с датами/abbrev'ами
    # («8. Mai», «St. Petersburg», «z. B.») в "" — что давало 60%
    # missing card_lead в production posts.
    if re.search(r"\b\d+\.\s*$", inner):
        return ""
    if re.search(r"\b[A-Za-zА-Яа-яЇїІіЄєҐґ]\.\s*$", inner):
        return ""
    # Multi-sentence guard: terminal punct + space + uppercase letter.
    if re.search(r"[.!?]\s+[A-ZА-ЯЇІЄҐ]", inner):
        return ""
    if _TRANSLATED_CARD_LEAD_SOURCE_ATTRIBUTION_RE.search(cleaned):
        return ""
    return cleaned


async def _call(user_prompt: str, system_prompt: str, api_key: str, provider: str, model: str, target_lang: str, source_text: str) -> TranslationResult:
    try:
        base_url = "https://api.deepseek.com/v1" if provider == "deepseek" else None
        model    = model or ("deepseek-chat" if provider == "deepseek" else "gpt-4o-mini")
        kwargs   = {"base_url": base_url} if base_url else {}
        client   = AsyncOpenAI(api_key=api_key, **kwargs)

        kwargs = {
            "model": model,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user",   "content": user_prompt},
            ],
            "response_format": {"type": "json_object"},
        }
        if provider != "deepseek" and model.startswith(("gpt-5", "o")):
            kwargs["extra_body"] = reasoning_extra_body(model, 8192)
        else:
            kwargs["temperature"] = 0.2
            kwargs["max_tokens"] = 2048
        resp = await client.chat.completions.create(**kwargs)
        raw = completion_text(resp)
        if not raw.strip():
            raise ValueError(f"empty {provider} response ({completion_debug(resp)})")
        data = json.loads(raw)
        title = str(data.get("title", "")).strip()
        lead = str(data.get("lead", "")).strip()
        body = str(data.get("body", "")).strip()
        card_lead = _sanitize_translated_card_lead(
            str(data.get("card_lead", "")).strip(),
            target_lang=target_lang,
        )
        if target_lang.lower().startswith("ukrain"):
            title = _normalize_ukrainian_names(title)
            lead = _normalize_ukrainian_names(lead)
            body = _normalize_ukrainian_names(body)
        title, lead, body = _strip_translation_added_first_names(title, lead, body, source_text, target_lang)
        if target_lang.lower().startswith("ukrain"):
            title = _fix_ukrainian_gender_agreement(_normalize_ukrainian_style(_normalize_ukrainian_title(title)))
            lead = _fix_ukrainian_gender_agreement(_move_ukrainian_source_attribution(_normalize_ukrainian_style(lead)))
            body = _fix_ukrainian_gender_agreement(_move_ukrainian_source_attribution(_normalize_ukrainian_style(body)))
            title, lead, body = _strip_translation_added_first_names(title, lead, body, source_text, target_lang)
            title = _fix_ukrainian_gender_agreement(_normalize_ukrainian_style(_normalize_ukrainian_title(_normalize_ukrainian_names(title))))
            lead = _fix_ukrainian_gender_agreement(_move_ukrainian_source_attribution(_normalize_ukrainian_style(_normalize_ukrainian_names(lead))))
            body = _fix_ukrainian_gender_agreement(_move_ukrainian_source_attribution(_normalize_ukrainian_style(_normalize_ukrainian_names(body))))
            title, lead, body = _repair_ukrainian_structure(title, lead, body)
            warnings = _ukrainian_style_warnings(title, lead, body)
            if warnings:
                raise ValueError("ukrainian editorial style check failed: " + "; ".join(warnings))
        else:
            title = _normalize_non_ukrainian_source_names(title)
            lead = _normalize_non_ukrainian_source_names(lead)
            body = _normalize_non_ukrainian_source_names(body)
            if _english_contains_cyrillic(title, lead, body):
                raise ValueError("english translation contains Cyrillic text")
        return TranslationResult(
            title=title,
            lead=lead,
            body=body,
            card_lead=card_lead,
            success=True,
            tokens=completion_total_tokens(resp),
        )
    except Exception as exc:
        logger.warning("Translation via %s failed: %s", provider, exc)
        return TranslationResult(error=str(exc))


def _normalize_ukrainian_title(title: str) -> str:
    cleaned = re.sub(r"\s+", " ", title).strip()
    if not cleaned:
        return cleaned
    if re.search(r"близькосхідн\w*\s+стрічк\w*|стрічк\w*\s+близькосхідн\w*", cleaned, re.I):
        return "Хроніка подій на Близькому Сході"
    cleaned = re.sub(r"\b[Сс]трічка\b", "хроніка", cleaned)
    cleaned = re.sub(r"\b[Сс]трічки\b", "хроніки", cleaned)
    if title[:1].isupper() and cleaned:
        cleaned = cleaned[:1].upper() + cleaned[1:]
    return cleaned


def _normalize_ukrainian_names(text: str) -> str:
    cleaned = text
    replacements = {
        "Міерш": "Мірш",
        "Міерша": "Мірша",
        "Миерш": "Мірш",
        "Мерш": "Мірш",
        "Зеєдер": "Зедер",
        "Зеєдера": "Зедера",
        "Зьодер": "Зедер",
        "Зьодера": "Зедера",
        "Сьодер": "Зедер",
        "Сьодера": "Зедера",
        "Зодер": "Зедер",
        "Зодера": "Зедера",
    }
    for src, dst in replacements.items():
        cleaned = re.sub(rf"\b{re.escape(src)}\b", dst, cleaned, flags=re.U)
    cleaned = re.sub(r"\bSPD\b", "СДПН", cleaned)
    cleaned = re.sub(r"\bМірш\s*\((?:Miersch)\)", "Мірш", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bЗедер\s*\((?:Söder|Soeder)\)", "Зедер", cleaned, flags=re.I | re.U)
    return cleaned


def _normalize_non_ukrainian_source_names(text: str) -> str:
    cleaned = re.sub(r"\bGermanyfunk\b", "Deutschlandfunk", text)
    cleaned = re.sub(r"\bGermany[’']s\s+Deutschlandfunk\b", "Deutschlandfunk", cleaned)
    cleaned = re.sub(r"\s*,?\s*повідомляє\s+([A-Z][A-Za-z0-9 ._-]+)\b", r", according to \1", cleaned, flags=re.U)
    cleaned = re.sub(r"\s*,?\s*за даними\s+([A-Z][A-Za-z0-9 ._-]+)\b", r", according to \1", cleaned, flags=re.U)
    cleaned = re.sub(r"\s*,?\s*заявив\s+([A-Z][A-Za-z0-9 ._-]+)\b", r", said \1", cleaned, flags=re.U)
    cleaned = re.sub(r"\bhis\s+Nina\b", "Nina", cleaned, flags=re.I)
    cleaned = re.sub(r"\bThe dancer and Nina have received their third child\b", "The dancer and Nina have welcomed their third child", cleaned, flags=re.I)
    cleaned = re.sub(r"\bhave received their third child\b", "have welcomed their third child", cleaned, flags=re.I)
    cleaned = re.sub(r"\bausterity package\b", "savings package", cleaned, flags=re.I)
    cleaned = re.sub(r"\b(?:Bavarian Minister[-‐‑‒–—―]President|Bavarian Prime Minister|Minister[-‐‑‒–—―]President|Prime Minister)\s+Söder\b", "Söder", cleaned)
    cleaned = re.sub(r"\b(?:Johannes|Matthias|Klaus)\s+Miersch\b", "Miersch", cleaned)
    return cleaned


def _english_contains_cyrillic(*parts: str) -> bool:
    return re.search(r"[А-Яа-яІіЇїЄєҐґ]", "\n".join(parts), re.U) is not None


def _fix_ukrainian_gender_agreement(text: str) -> str:
    """Catch common gender-agreement slips where the AI translator copied
    the German noun's gender onto a Ukrainian noun whose actual gender
    differs. The pattern: feminine/neuter adjective directly followed by
    a masculine UK noun (or vice versa). We only patch unambiguous cases.

    Examples (DE → wrong UK → corrected UK):
      die Parade → "військова парад" → "військовий парад"
      die Sitzung → "наступна засідання" → "наступне засідання"
      der Beschluss → "новий рішення" → "нове рішення"
    """
    if not text:
        return text
    # Maps: noun → correct gender ("m" / "f" / "n").
    # Only nouns where the AI commonly drifts away from real UK gender
    # because the German equivalent has a different gender.
    masc_nouns = [
        "парад", "звіт", "саміт", "баланс", "вечір", "конгрес",
        "процес", "форум", "проєкт", "проект", "комітет", "уряд",
        "вибір", "закон", "референдум", "удар", "наступ", "виступ",
        "доступ", "момент", "захід", "знак", "опис", "обмін",
    ]
    fem_nouns = [
        "сесія", "криза", "фракція", "гілка", "коаліція", "столиця",
        "промова", "заява", "пропозиція", "поправка",
    ]
    neut_nouns = [
        "засідання", "рішення", "повідомлення", "питання", "завдання",
        "ставлення", "становлення", "обладнання", "звернення", "обговорення",
        "підприємство", "міністерство", "посольство", "відомство",
    ]
    # adjective ending pairs (m, f, n) for nominative AND accusative
    # (Ukrainian: feminine accusative ends in -у/-ю, distinct from nominative -а/-я;
    # masculine accusative for inanimate = nominative; neuter same as nominative).
    adj_endings = [
        # (m, f, n) — nominative
        ("ий", "а", "е"),
        ("ій", "я", "є"),
        # accusative-feminine forms for the same adjective stems
        ("ий", "у", "е"),
        ("ій", "ю", "є"),
    ]

    def fix_pair(noun: str, target_gender: str) -> str:
        nonlocal text
        # Build regex: one or more adjectives followed by the noun.
        # We patch only when at least one adjective is in WRONG gender.
        for m_end, f_end, n_end in adj_endings:
            wrong_endings: list[str] = []
            right_end = ""
            if target_gender == "m":
                wrong_endings = [f_end, n_end]
                right_end = m_end
            elif target_gender == "f":
                wrong_endings = [m_end, n_end]
                right_end = f_end
            elif target_gender == "n":
                wrong_endings = [m_end, f_end]
                right_end = n_end
            for wrong_end in wrong_endings:
                # \b<root><wrong_end>\s+<noun>\b → <root><right_end> <noun>
                pattern = re.compile(
                    rf"\b([А-ЯҐЄІЇа-яґєії]{{2,}}){re.escape(wrong_end)}(\s+){re.escape(noun)}\b",
                    re.UNICODE,
                )
                text = pattern.sub(rf"\1{right_end}\2{noun}", text)
        return text

    for noun in masc_nouns:
        fix_pair(noun, "m")
    for noun in fem_nouns:
        fix_pair(noun, "f")
    for noun in neut_nouns:
        fix_pair(noun, "n")

    # Past-tense verb agreement: "пройшла парад" / "пройшло парад" → "пройшов парад"
    # Limit to a small known verb set to avoid false positives.
    verbs_past_root = ["пройш", "відбу", "розпоч", "заверш", "стартува", "трива"]
    for root in verbs_past_root:
        for noun in masc_nouns:
            text = re.sub(
                rf"\b({root})(?:ла|ло)(\s+(?:[А-ЯҐЄІЇа-яґєії]+\s+)?){re.escape(noun)}\b",
                rf"\1ов\2{noun}",
                text,
            )
    return text


def _normalize_ukrainian_style(text: str) -> str:
    cleaned = re.sub(r"\s+", " ", text).strip() if "\n" not in text else _normalize_paragraph_spacing(text)
    replacements = {
        "фракії": "фракції",
        "вбачає потребу": "вважає, що потрібні зміни",
        "вбачають потребу": "вважають, що потрібні зміни",
        "вбачає ще потребу": "вважає, що ще потрібні зміни",
        "бачить потребу у змінах до пакета": "вважає, що пакет треба змінити",
        "бачить потребу у змінах до пакету": "вважає, що пакет треба змінити",
        "доопрацювання пакету": "зміни до пакета",
        "доопрацювання пакета": "зміни до пакета",
        "доопрацюванні пакету": "змінах до пакета",
        "доопрацюванні пакета": "змінах до пакета",
        "подальші парламентські консультації": "подальший розгляд у парламенті",
        "подальших консультацій": "подальшого розгляду",
        "у згаданому матеріалі": "у матеріалі",
        "представленого пакету": "цього пакета",
        "представленого пакета": "цього пакета",
        "органами, відповідальними за законодавство": "профільними парламентськими структурами",
        "статутних фондів охорони здоров’я": "кас обов’язкового медичного страхування",
        "статутних фондів охорони здоров'я": "кас обов’язкового медичного страхування",
        "статутних фондів медичного страхування": "кас обов’язкового медичного страхування",
        "статутні фонди охорони здоров’я": "каси обов’язкового медичного страхування",
        "статутні фонди охорони здоров'я": "каси обов’язкового медичного страхування",
        "статутні фонди медичного страхування": "каси обов’язкового медичного страхування",
        "державних медичних страхових кас": "кас обов’язкового медичного страхування",
        "державних медичних страхових компаній": "кас обов’язкового медичного страхування",
        "державних медичних кас": "кас обов’язкового медичного страхування",
        "державних лікарняних кас": "кас обов’язкового медичного страхування",
        "державних страхових фондів": "кас обов’язкового медичного страхування",
        "державних страхових фондах": "касах обов’язкового медичного страхування",
        "медичних страхових компаній": "кас обов’язкового медичного страхування",
        "медичних страхових кас": "кас обов’язкового медичного страхування",
        "медичних кас": "кас обов’язкового медичного страхування",
        "лікарняних кас": "кас обов’язкового медичного страхування",
        "фондової системи охорони здоров’я": "кас обов’язкового медичного страхування",
        "фондової системи охорони здоров'я": "кас обов’язкового медичного страхування",
        "законодавчих страхових фондах": "касах обов’язкового медичного страхування",
        "законодавчих страхових фондів": "кас обов’язкового медичного страхування",
        "законодавчих фондів охорони здоров’я": "кас обов’язкового медичного страхування",
        "законодавчих фондів охорони здоров'я": "кас обов’язкового медичного страхування",
        "законодавчі фонди охорони здоров’я": "каси обов’язкового медичного страхування",
        "законодавчі фонди охорони здоров'я": "каси обов’язкового медичного страхування",
        "законодавчих фондах охорони здоров’я": "касах обов’язкового медичного страхування",
        "законодавчих фондах охорони здоров'я": "касах обов’язкового медичного страхування",
        "потребує змін; вони закликають внести конкретні зміни": "потребує змін",
        "потрібні зміни в подальшому обговоренні": "потрібне подальше обговорення",
        "потрібне додаткове опрацювання": "потрібне додаткове обговорення",
        "додаткове опрацювання": "додаткове обговорення",
        "потрібні зміни в змінах до": "потрібні зміни до",
        "зміни в змінах до": "зміни до",
        "вимагають доробок до": "вимагають змін до",
        "вимагає доробок до": "вимагає змін до",
        "кабінетом федерального уряду": "федеральним кабінетом",
        "кабінеті федерального уряду": "федеральному кабінеті",
        "додаткових подробиць у короткій замітці не наводиться": "додаткових подробиць у короткій замітці немає",
        "Танцівник та його Ніна отримали третю дитину.": "Танцівник і Ніна стали батьками втретє.",
        "Танцівник і його Ніна отримали третю дитину.": "Танцівник і Ніна стали батьками втретє.",
        "отримали третю дитину": "стали батьками втретє",
    }
    for src, dst in replacements.items():
        cleaned = re.sub(re.escape(src), dst, cleaned, flags=re.I)
    cleaned = re.sub(
        r"\bу\s+законодавч\w+\s+фармацевтичн\w+\s+сектор\w+\s+та\s+систем[іи]\s+обов[’']язкового\s+медичного\s+страхування\b",
        "у системі обов’язкового медичного страхування",
        cleaned,
        flags=re.I | re.U,
    )
    cleaned = re.sub(
        r"\bзаконодавч\w+\s+фармацевтичн\w+\s+сектор\w+\s+та\s+систем[іи]\s+обов[’']язкового\s+медичного\s+страхування\b",
        "системі обов’язкового медичного страхування",
        cleaned,
        flags=re.I | re.U,
    )
    cleaned = re.sub(
        r"\bзаконодавч\w+\s+фармацевтичн\w+\s+сектор\w+\b",
        "системі обов’язкового медичного страхування",
        cleaned,
        flags=re.I | re.U,
    )
    cleaned = re.sub(r"\bдоопрацюванням\b", "змінами", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bдоопрацювання\b", "зміни", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bдоопрацюванн[іяю]\b", "змін", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bдоопрацювати\b", "змінити", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bдоопрацюють\b", "змінять", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bдоопрацює\b", "змінить", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bдоопрацюван\w*\b", "змін", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bопрацюван\w*\b", "обговорення", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bзаконодавч\w+(?:\s+\w+){0,2}\s+фонд\w+\b", "кас обов’язкового медичного страхування", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bдержавн\w+(?:\s+\w+){0,2}\s+страхов\w+\s+фонд\w+\b", "кас обов’язкового медичного страхування", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bКабінет Міністрів\b", "федеральний кабінет", cleaned, flags=re.U)
    cleaned = re.sub(r"\bбачить потребу у змінах до пакета\b", "вважає, що пакет треба змінити", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bбачить потребу у змінах до пакету\b", "вважає, що пакет треба змінити", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bвимага(є|ють)\s+зміни\b", r"вимага\1 змін", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bпрос(ить|ять)\s+зміни\b", r"прос\1 змін", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bпотребу(є|ють)\s+зміни\b", r"потребу\1 змін", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bпакету\b", "пакета", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\.\s*[Пп]овідомляє\s+([A-Z][A-Za-z0-9 ._-]+)\.", r", повідомляє \1.", cleaned, flags=re.U)
    cleaned = re.sub(r"^\s*[Пп]ро це повідомляє\s+[A-Z][A-Za-z0-9 ._-]+\.\s*", "", cleaned, flags=re.U)
    cleaned = re.sub(r"([.!?])\s+додаткових\b", r"\1 Додаткових", cleaned, flags=re.U)
    return cleaned


def _normalize_paragraph_spacing(text: str) -> str:
    paragraphs = [re.sub(r"\s+", " ", part).strip() for part in re.split(r"\n{2,}", text) if part.strip()]
    return "\n\n".join(paragraphs)


def _move_ukrainian_source_attribution(text: str) -> str:
    if "\n" not in text:
        return _move_ukrainian_source_attribution_sentence(text)
    paragraphs = [
        _move_ukrainian_source_attribution_sentence(part.strip())
        for part in re.split(r"\n{2,}", text)
        if part.strip()
    ]
    return "\n\n".join(paragraphs)


def _move_ukrainian_source_attribution_sentence(text: str) -> str:
    patterns = [
        (r"^\s*([A-Z][A-Za-z0-9 ._-]+)\s+повідомляє:\s*(.+)$", "повідомляє"),
        (r"^\s*Як повідомляє\s+([^,]+),\s*(.+)$", "повідомляє"),
        (r"^\s*За даними\s+([^,]+),\s*(.+)$", "за даними"),
        (r"^\s*За повідомленням\s+([^,]+),\s*(.+)$", "за повідомленням"),
        (r"^\s*Згідно з повідомленням\s+([^,]+),\s*(.+)$", "згідно з повідомленням"),
    ]
    for pattern, formula in patterns:
        match = re.match(pattern, text, flags=re.I | re.U | re.S)
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
        return f"{rest}, {formula} {source}."
    return text


def _ukrainian_style_warnings(title: str, lead: str, body: str) -> list[str]:
    warnings: list[str] = []
    title_n = _norm_words(title)
    lead_n = _norm_words(lead)
    first_paragraph = next((p.strip() for p in re.split(r"\n{2,}", body) if p.strip()), body.strip())
    first_n = _norm_words(first_paragraph)

    # NOTE: title/lead and lead/first-paragraph similarity warnings
    # removed from the gate. They diagnose upstream German-master style,
    # not translation quality — if the German title and lead start with
    # the same 5 words, the translator can't fix that without changing
    # facts. Same opening is also natural in inverted-pyramid news for
    # subjects like "Bundeskanzler Merz". Real translation problems
    # (banned bureaucratic phrases, Cyrillic in EN, etc.) stay below.
    # NOTE: removed "lead and first paragraph both start with source
    # attribution" warning. Architecture-audit base_voice rule #2 mandates
    # source attribution in the lead; rule #10 mandates naming each
    # additional source when its fact is introduced. Translator faithfully
    # carries that pattern into Ukrainian — flagging it here would produce
    # a self-defeating regen loop. Real over-attribution is still caught
    # by the source_starts threshold below.

    combined = f"{title}\n{lead}\n{body}".lower()
    source_starts = len(re.findall(r"(?m)^\s*(як повідомляє|за даними|за повідомленням|згідно з повідомленням|у повідомленні)", combined))
    # Threshold raised from 2 to 4 so a typical 2–3 source synthesis (each
    # source attributed once) does not trip the gate. 4+ paragraph-starts
    # still flag — that's mechanical repetition, not editorial discipline.
    if source_starts >= 4:
        warnings.append("too many paragraphs start with source attribution")

    banned_patterns = {
        r"\bвбачає( ще)? потребу\b": "bureaucratic phrase 'вбачає потребу'",
        r"\bвбачають( ще)? потребу\b": "bureaucratic phrase 'вбачають потребу'",
        r"\bбачить потребу\b": "bureaucratic phrase 'бачить потребу'",
        r"\bз огляду на\b": "bureaucratic phrase 'з огляду на'",
        r"\bдоопрацюван\w*\b": "bureaucratic phrase 'доопрацювання'",
        r"\bопрацюван\w*\b": "bureaucratic phrase 'опрацювання'",
        r"\bдоробк\w*\b": "bureaucratic phrase 'доробок'",
        r"\bу повідомленні не деталізовано\b": "bureaucratic phrase 'у повідомленні не деталізовано'",
        r"\bподальш\w+ парламентськ\w+ консультац": "bureaucratic phrase 'парламентські консультації'",
        r"\bорган\w*, відповідальн\w+ за законодавство\b": "bureaucratic legislative formula",
        r"\bстатутн\w+ фонд": "german legal calque 'статутні фонди'",
        r"\bзаконодавч\w+(?:\s+\w+){0,2}\s+фонд": "german legal calque 'законодавчі фонди'",
        r"\bдержавн\w+(?:\s+\w+){0,2}\s+страхов\w+\s+фонд": "german legal calque 'державні страхові фонди'",
        r"\bфондов\w+ систем": "german legal calque 'фондова система'",
        r"\bфармацевтичн\w+\s+сектор": "wrong health-insurance calque 'фармацевтичний сектор'",
        r"\bфракії\b": "typo 'фракії'",
        r"\bКабінет Міністрів\b": "wrong institution calque 'Кабінет Міністрів'",
    }
    for pattern, label in banned_patterns.items():
        if re.search(pattern, combined, re.I | re.U):
            warnings.append(label)

    return warnings


def _repair_ukrainian_structure(title: str, lead: str, body: str) -> tuple[str, str, str]:
    first_paragraph = next((p.strip() for p in re.split(r"\n{2,}", body) if p.strip()), body.strip())
    if _same_opening(_norm_words(lead), _norm_words(first_paragraph), 5):
        body = _drop_first_sentence(body)
    if _same_opening(_norm_words(title), _norm_words(lead), 5):
        lead = _drop_first_sentence(lead)
    return title.strip(), lead.strip(), body.strip()


def _drop_first_sentence(text: str) -> str:
    cleaned = text.strip()
    if not cleaned:
        return cleaned
    parts = re.split(r"(?<=[.!?…])\s+", cleaned, maxsplit=1)
    if len(parts) == 2:
        return parts[1].strip()
    return cleaned


def _norm_words(text: str) -> list[str]:
    lowered = text.lower().replace("’", "'")
    lowered = re.sub(r"[^\wа-яіїєґ' -]+", " ", lowered, flags=re.U)
    return [part for part in re.split(r"\s+", lowered.strip()) if part]


def _same_opening(a: list[str], b: list[str], n: int) -> bool:
    if len(a) < n or len(b) < n:
        return False
    return a[:n] == b[:n]


def _starts_with_source_formula(text: str) -> bool:
    return re.match(r"^\s*(як повідомляє|за даними|за повідомленням|згідно з повідомленням|у повідомленні|про це повідомляє)\b", text, re.I | re.U) is not None


_SURNAME_SKIP = {
    "Deutschland", "Deutschlandfunk", "Krankenkassen", "Bundeskabinett",
    "Sparpaket", "Gesetzliche", "Nachbesserungen", "SPD-Fraktionschef",
    "Blick", "Weg", "Diskussionsbedarf", "Die", "Weitere", "Kurzmeldung",
}

_ROLE_WORDS_BEFORE_SURNAME = {
    "Fraktionschef", "Fraktionschefin", "Bundeskanzler", "Bundeskanzlerin",
    "Ministerpräsident", "Ministerpraesident", "Ministerpräsidentin",
    "Parteichef", "Parteichefin", "Vorsitzender", "Vorsitzende",
    "Präsident", "Präsidentin", "Praesident", "Praesidentin",
}

_UK_SURNAME_VARIANTS = {
    "Miersch": ["Мірш", "Мірша", "Міршем", "Міерш", "Міерша", "Миерш", "Мерш"],
    "Söder": ["Зедер", "Зедера", "Зедером", "Зеєдер", "Зеєдера", "Зьодер", "Сьодер", "Зодер"],
    "Soeder": ["Зедер", "Зедера", "Зедером", "Зеєдер", "Зеєдера", "Зьодер", "Сьодер", "Зодер"],
    "Merz": ["Мерц", "Мерца", "Мерцом"],
}

_CYRILLIC_NOT_FIRST_NAMES = {
    "Бундестаг", "Бундестазі", "СДПН", "Німеччини", "Німецьке",
    "Соціал-демократичної", "Федеральний", "Федерального",
}


def _strip_translation_added_first_names(title: str, lead: str, body: str, source_text: str, target_lang: str) -> tuple[str, str, str]:
    surnames = _source_surnames_without_first_names(source_text)
    return tuple(
        _strip_translation_added_first_names_from_text(part, surnames, source_text, target_lang)
        for part in (title, lead, body)
    )


def _source_surnames_without_first_names(source_text: str) -> list[str]:
    source = source_text or ""
    tokens = re.findall(r"\b[A-ZÄÖÜ][A-Za-zÄÖÜäöüß-]{2,}\b", source)
    surnames: list[str] = []
    for token in tokens:
        if token in _SURNAME_SKIP or token.isupper():
            continue
        if _source_has_explicit_first_name(source, token):
            continue
        if token not in surnames:
            surnames.append(token)
    return surnames


def _strip_translation_added_first_names_from_text(text: str, surnames: list[str], source_text: str, target_lang: str) -> str:
    cleaned = text
    for surname in surnames:
        cleaned = _strip_latin_first_name_before_surname(cleaned, surname, source_text)
        if target_lang.lower().startswith("ukrain"):
            for variant in _UK_SURNAME_VARIANTS.get(surname, []):
                cleaned = _strip_ukrainian_added_role_before_surname(cleaned, variant, source_text)
                cleaned = _strip_cyrillic_first_name_before_surname(cleaned, variant)
    return cleaned


def _strip_latin_first_name_before_surname(text: str, surname: str, source_text: str) -> str:
    if _source_has_explicit_first_name(source_text, surname):
        return text
    cleaned = re.sub(
        rf"\b(?:Bavarian Minister[-‐‑‒–—―]President|Bavarian Prime Minister|Minister[-‐‑‒–—―]President|Prime Minister|CSU leader)\s+{re.escape(surname)}\b",
        surname,
        text,
    )
    cleaned = re.sub(
        rf"\bBavaria[’']s\s+{re.escape(surname)}\b",
        surname,
        cleaned,
    )
    return re.sub(
        rf"\b[A-ZÄÖÜ][A-Za-zÄÖÜäöüß-]{{2,}}[ \t]+{re.escape(surname)}\b",
        surname,
        cleaned,
    )


def _strip_cyrillic_first_name_before_surname(text: str, surname_variant: str) -> str:
    pattern = re.compile(
        rf"\b([А-ЯІЇЄҐ][а-яіїєґ'’ʼ-]{{2,}})\s+({re.escape(surname_variant)})\b",
        flags=re.U,
    )

    def replace(match: re.Match[str]) -> str:
        first = match.group(1)
        if first in _CYRILLIC_NOT_FIRST_NAMES:
            return match.group(0)
        return match.group(2)

    return pattern.sub(replace, text)


def _strip_ukrainian_added_role_before_surname(text: str, surname_variant: str, source_text: str) -> str:
    if re.search(r"\b(Ministerpräsident|Ministerpraesident|Minister-President|Bayern|Bavaria|CSU)\b", source_text or "", re.I):
        return text
    cleaned = text
    cleaned = re.sub(
        rf"\b(?:прем['’ʼ]єр(?:\s+Баварії)?|прем['’ʼ]єр-міністр(?:\s+Баварії)?|міністр-президент(?:\s+Баварії)?|лідер\s+ХСС|голова\s+ХСС|політик)\s+[А-ЯІЇЄҐ][а-яіїєґ'’ʼ-]{{2,}}\s+{re.escape(surname_variant)}\b",
        surname_variant,
        cleaned,
        flags=re.I | re.U,
    )
    cleaned = re.sub(
        rf"\b{re.escape(surname_variant)},\s*(?:прем['’ʼ]єр(?:\s+Баварії)?|прем['’ʼ]єр-міністр(?:\s+Баварії)?|міністр-президент(?:\s+Баварії)?|лідер\s+ХСС|голова\s+ХСС|політик),\s*",
        f"{surname_variant} ",
        cleaned,
        flags=re.I | re.U,
    )
    return re.sub(
        rf"\b(?:прем['’ʼ]єр(?:\s+Баварії)?|прем['’ʼ]єр-міністр(?:\s+Баварії)?|міністр-президент(?:\s+Баварії)?|лідер\s+ХСС|голова\s+ХСС|політик)\s+{re.escape(surname_variant)}\b",
        surname_variant,
        cleaned,
        flags=re.I | re.U,
    )


def _source_has_explicit_first_name(source_text: str, surname: str) -> bool:
    pattern = re.compile(rf"\b([A-ZÄÖÜ][a-zÄÖÜäöüß]{{2,}})[ \t]+{re.escape(surname)}\b")
    for match in pattern.finditer(source_text or ""):
        if match.group(1) not in _ROLE_WORDS_BEFORE_SURNAME:
            return True
    return False
