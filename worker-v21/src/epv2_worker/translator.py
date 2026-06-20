"""
EuroPulse AutoPilot v2.1 — Translator
Translates German master → Ukrainian + English.
"""
from __future__ import annotations

import json
import logging
import os
import re
from dataclasses import dataclass, field

from openai import AsyncOpenAI

from .openai_compat import (
    completion_cached_tokens,
    completion_debug,
    completion_text,
    completion_total_tokens,
    reasoning_extra_body,
)
from .provider_health import (
    provider_available,
    provider_unavailable_reason,
    register_provider_failure,
    register_provider_success,
)

logger = logging.getLogger(__name__)

# R10 Phase 2 2026-05-14: spaCy uk_core_news_lg для lemma-based filler detection.
# Lazy load (один раз на process). Catches morphological variants which regex
# misses (e.g. "слід зазначити" / "слід зазначати" / "було зазначено" share
# lemma sequence ['слід', 'зазначити']).
_UK_NLP = None
_UK_NLP_LOAD_FAILED = False

def _get_uk_nlp():
    global _UK_NLP, _UK_NLP_LOAD_FAILED
    if _UK_NLP is not None:
        return _UK_NLP
    if _UK_NLP_LOAD_FAILED:
        return None
    if os.getenv("EPV2_ENABLE_SPACY_UK_NER", "0").strip().lower() not in {"1", "true", "yes", "on"}:
        _UK_NLP_LOAD_FAILED = True
        logger.info("spaCy UK NER disabled by default to keep worker RSS bounded")
        return None
    try:
        import spacy  # type: ignore
        _UK_NLP = spacy.load("uk_core_news_lg")
        logger.info("spaCy uk_core_news_lg loaded for lemma-based filler detection")
        return _UK_NLP
    except Exception as exc:  # noqa: BLE001
        logger.warning("spaCy UK model unavailable, lemma filler detection skipped: %s", exc)
        _UK_NLP_LOAD_FAILED = True
        return None

# Canonical filler lemma sequences (2-3 lemmas). Catches all morphological
# variants automatically. Не пересекается со старым regex detector — purely
# additive. Operator может tune threshold отдельно по `lemma_filler_count`.
_UK_FILLER_LEMMA_SEQUENCES: list[tuple[str, ...]] = [
    ("слід", "зазначити"),
    ("слід", "зауважити"),
    ("варто", "зазначити"),
    ("варто", "зауважити"),
    ("необхідно", "враховувати"),
    ("у", "цей", "контекст"),
    ("в", "цей", "контекст"),
    ("як", "видно"),
    ("як", "відомо"),
    ("як", "вже", "зазначатися"),
    ("на", "наш", "погляд"),
    ("на", "думка", "експерт"),
    ("експерт", "вважати"),
    ("аналітик", "припускати"),
    ("спостерігач", "відзначати"),
    ("важливо", "відзначити"),
    ("слід", "пам'ятати"),
    ("в", "результат", "це"),
    ("як", "результат"),
    ("у", "висновок"),
    ("в", "цілий"),
    ("в", "загальний"),
    ("в", "сучасний", "умова"),
]


def _count_filler_lemmas(text: str) -> tuple[int, list[str]]:
    """R10 Phase 2: lemma-based filler counter. Returns (count, samples).

    Catches morphological variants which regex misses. Idempotent —
    каждое lemma sequence считается раз на occurrence в тексте.
    """
    if not text:
        return 0, []
    nlp = _get_uk_nlp()
    if nlp is None:
        return 0, []
    try:
        doc = nlp(text[:50000])  # cap to avoid pathological docs
    except Exception as exc:  # noqa: BLE001
        logger.warning("spaCy UK pass failed: %s", exc)
        return 0, []
    lemmas = [tok.lemma_.lower() for tok in doc if not tok.is_punct and not tok.is_space]
    total = 0
    samples: list[str] = []
    for seq in _UK_FILLER_LEMMA_SEQUENCES:
        n = len(seq)
        for i in range(len(lemmas) - n + 1):
            if tuple(lemmas[i:i + n]) == seq:
                total += 1
                if len(samples) < 5:
                    samples.append(" ".join(seq))
    return total, samples


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
    # R5 2026-05-14: OpenAI prompt caching (50% discount). DeepSeek → 0.
    cached_tokens: int = 0
    # Anti-plagiarism gate (architecture phase 3) — translation is also
    # checked against the primary-source text in its source language.
    uniqueness_pct: float = 100.0
    uniqueness_passed: bool = True
    uniqueness_reason: str = ""
    uniqueness_shared: list = field(default_factory=list)
    # 2026-05-13: filler / style concerns. Не блокируют публикацию, но pipeline
    # эмитит soft warnings → PHP gate решает на основе importance/source.
    style_filler_count: int = 0
    style_filler_samples: list = field(default_factory=list)


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
- Lateinisch geschriebene Eigennamen, Publisher-, Marken-, Produkt- und Organisationsnamen NICHT phonetisch übersetzen oder kyrillisieren. Wenn der DE-Master oder die Story-Card „Kyiv Post", „Deutsche Welle", „OpenAI", „Waymo", „ProSieben" usw. schreibt, bleibt diese Schreibweise auch in Ukrainisch erhalten.
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
- 2026-05-13: KOMPOUND-SUBSTANTIVE — präzise Ein-Wort-Übersetzungen, KEINE Adjektivkette aus 3-4 Wörtern. Deutsche Compound nouns ins Ukrainische:
  • Gruppendynamik → «групова динаміка» (НЕ «груповий динамічний веселощі»)
  • Sicherheitsbedenken → «занепокоєння щодо безпеки»
  • Wirtschaftsaufschwung → «економічне піднесення»
  • Klimakrise → «кліматична криза»
  • Bürokratieabbau → «дебюрократизація» / «скорочення бюрократії»
  • Energiewende → «енергетичний перехід» / «енергоперехід»
  • Mitspracherecht → «право голосу»
  • Vertrauensvotum → «вотум довіри»
  • Cybersicherheit → «кібербезпека»
  • Künstliche Intelligenz → «штучний інтелект» (НЕ «КІ» abbreviation)
  • Verteidigungsfähigkeit → «обороноздатність» (НЕ «військова здатність»)
  • Reformbedarf → «потреба у реформах» (НЕ «вбачає потребу»)
  • Gesetzentwurf → «законопроєкт»
  • Wahlumfrage → «опитування про вибори»
  • Sprachgrenze → «мовний бар'єр»
  Wenn unsicher — eine zusammengefasste 2-Wort-Phrase ist besser als eine 4-Wort-Adjektivkette, die keinen syntaktischen Sinn ergibt.
- POLITISCHE ZUSCHREIBUNG: «Democratic and Republican lawmakers» → «законодавці-демократи та республіканці» (НЕ «демократичні та республіканські законодавці»). Parteizugehörigkeit als Apposition mit Bindestrich, nicht als Adjektiv.
- Für Ukrainisch — Genus-Übereinstimmung Pflicht: deutsche Substantive übernehmen ihr Geschlecht NICHT auf das ukrainische Wort. „die Parade" (DE: feminin) → „парад" (UK: maskulin). Adjektive, Verben und Pronomen müssen sich nach dem ukrainischen Geschlecht richten, nicht nach dem deutschen. Korrekt: „військовий парад", „пройшов парад", „цей парад"; FALSCH: „військова парад", „пройшла парад", „ця парад". Das Gleiche gilt für: „der Bericht" → „звіт" (m, nicht f), „der Saldo" → „баланс" (m), „der Abend" → „вечір" (m), „die Krise" → „криза" (f, übereinstimmt), „das Unternehmen" → „підприємство" (n, übereinstimmt), „der Beschluss" → „рішення" (n, NICHT m), „die Sitzung" → „засідання" (n, NICHT f).
- Titel, Lead und erster Absatz müssen unterschiedliche Aufgaben erfüllen: Titel meldet die Nachricht, Lead erklärt die Relevanz in 1–2 Sätzen, der erste Absatz führt mit neuen Details weiter. Nicht alle drei mit derselben Quellenformel oder denselben ersten Wörtern beginnen.
- Der erste Absatz darf den Lead nicht nacherzählen. Er muss konkretisieren: wer betroffen ist, was sich ändert, welche offenen Punkte es gibt oder was als Nächstes passiert.
- ANTI-FÜLLTEXT — diese Phrasen sind in der Übersetzung VERBOTEN, auch wenn das deutsche Original sie enthält (dann beim Übersetzen weglassen, nicht hinzufügen):
  • UK: «можливі наслідки», «це рішення може вплинути», «офіційне підтвердження поки що відсутнє», «подальші деталі поки не відомі», «це піднімає питання», «залишається спостерігати», «експерти вбачають у цьому», «це свідчить про…», «це підкреслює…», «це відображає…», «це вказує на…», «це демонструє…», «зростаюче занепокоєння», «зростаючу стурбованість», «викликає занепокоєння», «у зв'язку з цим», «з огляду на це».
  • EN: «possible consequences», «could affect», «official confirmation is still pending», «further details are not yet known», «this raises questions», «it remains to be seen», «observers see this as», «this could indicate…», «this reflects…», «this underscores growing concerns», «growing concerns about», «highlights mounting tensions», «in light of this», «against this backdrop» (если без конкретного факта).
  Statt solcher leeren Sätze: kürzere Übersetzung. Lieber 150 dichte Wörter als 350 mit Wassertext.
- Wenn das deutsche Original einen Absatz hat, der nur aus solchen Filler-Sätzen besteht — diesen Absatz in der Übersetzung WEGLASSEN. Lückenhafte Quelle bleibt lückenhafte Quelle, in jeder Sprache.
- ALLE ABSÄTZE DECKEN (2026-05-12 W2.1, перенесено из base_voice.py): Der DE-Master hat N Absätze (paragraphs <p>...</p>). Die UK- und EN-Übersetzung MÜSSEN ebenfalls N Absätze haben — keine wegen "Filler" zusammenfassen, keinen vollständig auslassen. AUSNAHME: Wenn ein Absatz wirklich NUR aus Filler-Phrasen (siehe Liste oben) besteht — dann darf er entfallen (vorherige Regel). Aber niemals einen substantiven Absatz weglassen, nur weil der Translator ihn als "redundant" empfindet. Erster Absatz ist NIE Filler — er trägt Subjekt-Einführung; wenn UK den ersten weglässt, hängen Pronomen im zweiten Absatz in der Luft.
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

- ПЕРЕКЛАД БЕЗ КАЛЬКИ І ДОСЛІВНОСТІ — semantic accuracy критично важлива:
  • "channel crossings" → "перетинання Ла-Маншу" або "переправа через Ла-Манш", НЕ "перетворення" (transformations)
  • "small boat crossings" → "переправа малими човнами" / "small boat crossings", НЕ "перетворення на малих човнах"
  • "coalition" → "коаліція" / "coalition", НЕ калька з конкретними посиланнями
  • "reform stau" / "reform backlog" → "затримка реформ" / "reform stalemate"
  • "strike" → "страйк" (workforce action) АБО "удар" (military) — контекст вирішує
  • "operation" → "операція" (military/medical) АБО "експлуатація" (system) — never confuse
  • Якщо англійський/німецький термін має decadeя значень — перекладай за context, не за first dictionary entry
- ENGLISH translation guards:
  • Cyrillic chars NEVER appear in English text. Якщо в source Ukrainian/Russian name (e.g. "Зеленський") — transliterate via BGN/PCGN ("Zelensky" / "Zelenskyy")
  • German umlauts стандартно preserved у English (Söder, Müller, Bärbel — leave as-is)
  • Russian names: Putin, Lavrov, Medvedev — standard romanization
- UKRAINIAN translation guards:
  • Latin-script proper names from the source/story-card are preserved as Latin, especially publishers, brands, products, platforms, companies and acronyms. НЕ пиши „Kyiv Post" як „Киівпост" або „Київ Пост"; НЕ пиши „Deutsche Welle" як „Дойтше Велле".
  • ЖОРСТКЕ ПРАВИЛО (2026-06-16): БУДЬ-ЯКА латинська назва, бренд, платформа,
    компанія, назва шоу/фільму/пісні/гри, та акронім ЗАЛИШАЄТЬСЯ ЛАТИНКОЮ
    дослівно, як у німецькому майстрі. ЗАБОРОНЕНО передавати їх кирилицею
    по літерах. Реальні помилки, яких НЕ має бути:
      Cirque du Soleil → НЕ «Кіркуе ду Солайл» (лишай Cirque du Soleil)
      All You Need Is Love → НЕ «Алл Иоу Неед Іс Лове» (лишай як є)
      YouTube → НЕ «ИоуТубе»; Facebook → НЕ «Факебоок»; Snapchat → НЕ «Снапхат»;
      TikTok, Instagram, X, SpaceX, xAI, Netflix, Spotify, Queen, Beatles,
      Sister Act, Ohnsorg-Theater — усі лишаються латинкою/оригіналом.
    Літера «И» (російська) у українському тексті — завжди БАГ.
  • Brand/source names стандартно preserve (AfD, CDU, EU, NATO, SAP, BMW, Kyiv Post, Deutsche Welle, OpenAI, Waymo, ProSieben)
  • Visual confusables — Latin "o" в Cyrillic context це BUG, завжди Cyrillic "о"

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


_LATIN_MULTIWORD_RE = re.compile(
    r"[A-ZÀ-ÖØ-Þ][A-Za-zÀ-ÿ.&\-]+(?:\s+(?:du|de|la|von|van|der|[A-ZÀ-ÖØ-Þ][A-Za-zÀ-ÿ.&\-]+)){1,4}"
)
_LATIN_ACRONYM_RE = re.compile(r"\b[A-Z]{2,6}\b")
_LATIN_CAMEL_RE = re.compile(r"\b[A-ZÀ-ÖØ-Þ][a-zà-ÿ]+[A-ZÀ-ÖØ-Þ][A-Za-zÀ-ÿ]+\b")


def _latin_name_candidates(text: str) -> set[str]:
    """Кандидаты в латинские имена собственные/бренды/издания: многословные
    с заглавных, аббревиатуры (NATO), camelCase (YouTube, ProSieben)."""
    cands: set[str] = set()
    for rx in (_LATIN_MULTIWORD_RE, _LATIN_ACRONYM_RE, _LATIN_CAMEL_RE):
        for m in rx.finditer(text or ""):
            s = m.group(0).strip()
            if len(s) >= 3:
                cands.add(s)
    return cands


_UK_TRANSLIT_SYSTEM = """Du korrigierst NUR falsch ins Kyrillische transliterierte lateinische
Eigennamen, Marken, Publikations-/Produkt-/Organisationsnamen in einem UKRAINISCHEN Text.

Du bekommst das DEUTSCHE Original (mit korrekter lateinischer Schreibweise) und die
UKRAINISCHE Übersetzung. Finde Namen, die in der UK-Version kyrillisch transliteriert
oder als Misch-Schrift-Kauderwelsch erscheinen, z. B.:
  «Сюддойтшер Zeitung» → «Süddeutsche Zeitung»
  «Факебоок» → «Facebook», «ИоуТубе» → «YouTube»
  «Кіркуе ду Солайл» → «Cirque du Soleil», «Дойтше Велле» → «Deutsche Welle»

Regel: solche Namen behalten die LATEINISCHE Schreibweise aus dem DEUTSCHEN Original
(bzw. die etablierte korrekte Form). Übliche übersetzte Wörter, Ländernamen, Personen,
die korrekt ukrainisiert sind (z. B. «Трамп», «Зеленський», «НАТО»), NICHT anfassen.

Antworte AUSSCHLIESSLICH mit JSON:
{"ops": [{"wrong": "<exakt wie im UK-Text>", "correct": "<korrekte lateinische Schreibweise>"}]}
Keine Funde → {"ops": []}."""


async def _repair_uk_latin_names(
    result: "TranslationResult", german_text: str, provider: str, api_key: str, model: str
) -> None:
    """Детерминированно чинит гарбленную транслитерацию латинских имён в UK.
    Гейт: если латинские имена из немецкого отсутствуют в UK дословно → дешёвый
    LLM-проход возвращает {wrong→correct}, замена выполняется в КОДЕ (надёжно)."""
    try:
        uk_blob = "\n".join([result.title or "", result.lead or "", result.card_lead or "", result.body or ""])
        cands = _latin_name_candidates(german_text)
        at_risk = [c for c in cands if c not in uk_blob]
        if not at_risk:
            return
        client = AsyncOpenAI(api_key=api_key, base_url="https://api.deepseek.com/v1") if provider == "deepseek" else AsyncOpenAI(api_key=api_key)
        user = (
            "DEUTSCHES ORIGINAL (korrekte Latein-Namen):\n" + (german_text or "")[:4000]
            + "\n\nUKRAINISCHE ÜBERSETZUNG (zu prüfen):\n" + uk_blob[:4000]
            + "\n\nVerdächtige Latein-Namen (im DE vorhanden, im UK nicht wörtlich): "
            + ", ".join(sorted(at_risk)[:30])
            + "\n\nGib Korrektur-ops als JSON."
        )
        resp = await client.chat.completions.create(
            model=model,
            messages=[{"role": "system", "content": _UK_TRANSLIT_SYSTEM}, {"role": "user", "content": user}],
            response_format={"type": "json_object"},
            temperature=0.0,
            max_tokens=800,
        )
        raw = (resp.choices[0].message.content or "").strip()
        if not raw:
            return
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            data = json.loads(raw, strict=False)
        ops = data.get("ops") or []
        applied = 0
        for op in ops:
            wrong = str(op.get("wrong") or "").strip()
            correct = str(op.get("correct") or "").strip()
            if not wrong or not correct or wrong == correct or len(wrong) < 2:
                continue
            if wrong in uk_blob:
                result.title = result.title.replace(wrong, correct)
                result.lead = result.lead.replace(wrong, correct)
                result.card_lead = result.card_lead.replace(wrong, correct)
                result.body = result.body.replace(wrong, correct)
                applied += 1
        if applied:
            logger.info("uk translit repair: fixed %d garbled latin name(s)", applied)
    except Exception as exc:  # noqa: BLE001
        logger.warning("uk translit repair skipped: %s", exc)


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
    original_text: str = "",
    original_lang: str = "",
) -> TranslationResult:
    system = _SYSTEM_PROMPT_TEMPLATE.format(target_lang=target_lang)
    card_lead_block = (
        f"\n\nKARTEN-LEAD (DE) — bitte als card_lead in {target_lang} übertragen:\n{card_lead_de}"
        if card_lead_de else ""
    )
    story_block = _format_story_card_for_translator(story_card) if story_card else ""
    # 2026-05-12 — Cross-language quote fidelity. Pipeline UA-source: 24tv,
    # pravda, Ukrinform etc. → DE master → UK перевод. Двойной hop теряет
    # original Peskov-style direct quotes (audit нашёл «Erfahrungshorizont»
    # вместо «багаж напрацювань»). Если target_lang совпадает с source_lang
    # (UA-источник → UK перевод, EN-источник → EN перевод), даём translator'у
    # original-text как референс для direct quotes: цитаты в кавычках брать
    # из original, а не back-translate из DE.
    _norm = (original_lang or "").strip().lower()[:2]
    _target_code = "uk" if target_lang.lower().startswith("ukrain") else ("en" if target_lang.lower().startswith("engl") else "")
    original_block = ""
    if original_text and _norm and _target_code and _norm == _target_code:
        snippet = original_text[:2500]
        original_block = (
            f"\n\n--- ORIGINAL ({original_lang.upper()}) — Quelle der Story ---\n"
            f"{snippet}\n--- ENDE ORIGINAL ---\n\n"
            f"WICHTIG (Cross-Language-Treue, 2026-05-12):\n"
            f"Diese Story wurde ursprünglich auf {target_lang} berichtet. Der DE-Master oben\n"
            f"ist Übersetzung aus dem Originaltext. Für deine {target_lang}-Version:\n"
            f"  1. Direkte Zitate (in Anführungszeichen) MÜSSEN möglichst wörtlich aus dem\n"
            f"     ORIGINAL oben übernommen werden, nicht back-translated aus dem DE-Master.\n"
            f"     Beispiel UK: wenn das Original «багаж напрацювань» sagt — du schreibst\n"
            f"     ebenfalls «багаж напрацювань», NICHT «Erfahrungshorizont» aus dem DE.\n"
            f"  2. Eigennamen, Funktionen, Geografie kommen aus DE-Master (oder Story-Card).\n"
            f"     Fakten und Reihenfolge des DE-Master bleiben erhalten.\n"
            f"  3. Wenn das Original ein Zitat hat, das im DE-Master gekürzt wurde — du nimmst\n"
            f"     trotzdem nur das, was der DE-Master abdeckt. Keine Zusatz-Fakten aus Original.\n"
        )
    user = (
        f"TITEL (DE):\n{title_de}\n\n"
        f"TEASER (DE):\n{lead_de}{card_lead_block}\n\n"
        f"ARTIKEL (DE):\n{body_de[:3000]}"
        f"{story_block}"
        f"{original_block}"
    )
    source_text = f"{title_de}\n{lead_de}\n{body_de}"

    candidates = provider_order or [
        ("openai", openai_api_key, "gpt-4o-mini"),
        ("deepseek", deepseek_api_key, "deepseek-chat"),
    ]
    for provider, api_key, model in candidates:
        if not api_key:
            continue
        if not provider_available(provider):
            logger.warning("Translation via %s skipped: cooldown %s", provider, provider_unavailable_reason(provider))
            continue
        last_result = TranslationResult(error="")
        for attempt in range(2):
            attempt_user = user
            if attempt > 0:
                attempt_user += (
                    "\n\nRETRY DIRECTIVE: The previous translation failed an automatic language/style gate. "
                    f"Return the complete article in {target_lang} only. Do not copy German sentences. "
                    "Keep the JSON fields title, lead, card_lead and body."
                )
            result = await _call(attempt_user, system, api_key, provider, model, target_lang, source_text)
            last_result = result
            if result.success:
                result.provider = provider
                result.model = model or ("deepseek-chat" if provider == "deepseek" else "gpt-4o-mini")
                register_provider_success(provider)
                _annotate_translation_uniqueness(result, source_text=source_text, target_lang=target_lang, story_card=story_card)
                # Адресный антиплагиат-retry (2026-06-10): если перевод слишком
                # близок к источнику (<85%), один раз переписываем именно
                # совпавшие фразы — как делает rewriter для DE. Раньше такой
                # перевод возвращался как есть → pipeline ставил hard-блокер
                # plagiarism_gate_uk/en → rebuild_bundle cap → ready_review.
                if not result.uniqueness_passed and result.uniqueness_shared:
                    listed = "\n".join(f"- {p}" for p in result.uniqueness_shared[:30])
                    plag_prompt = (
                        user
                        + f"\n\nANTI-PLAGIARISM RETRY: Die {target_lang}-Übersetzung war zu nah am Text "
                        f"(Originalität {result.uniqueness_pct:.0f}%, Ziel 85%+). Schreibe sie neu mit "
                        f"eigener Satzarchitektur und idiomatischem {target_lang}. Diese Wortfolgen "
                        "komplett anders formulieren (Eigennamen dürfen bleiben):\n" + listed
                    )
                    retry = await _call(plag_prompt, system, api_key, provider, model, target_lang, source_text)
                    if retry.success:
                        retry.provider = provider
                        retry.model = model or ("deepseek-chat" if provider == "deepseek" else "gpt-4o-mini")
                        _annotate_translation_uniqueness(retry, source_text=source_text, target_lang=target_lang, story_card=story_card)
                        if retry.uniqueness_passed or retry.uniqueness_pct >= result.uniqueness_pct:
                            result = retry
                # 2026-06-20 Детерминированный фикс гарбленной транслитерации
                # латинских имён в UK (Сюддойтшер Zeitung → Süddeutsche Zeitung).
                # Промпт-правила протекают; этот пост-проход чинит в коде.
                if _target_code == "uk":
                    await _repair_uk_latin_names(result, source_text, provider, api_key, model)
                return result
            if not _translation_error_retryable(result.error):
                break
        register_provider_failure(provider, last_result.error)

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


def _story_card_entities(card: dict | None) -> list:
    """Имена/организации/места из story card — чтобы антиплагиат не штрафовал
    за неизбежные совпадения по собственным именам (как делает rewriter)."""
    entities: list = []
    if not isinstance(card, dict):
        return entities
    for person in card.get("entities_people") or []:
        if isinstance(person, dict) and person.get("name"):
            entities.append(str(person["name"]))
    for org in card.get("entities_organizations") or []:
        if isinstance(org, dict) and org.get("name"):
            entities.append(str(org["name"]))
        elif isinstance(org, str) and org:
            entities.append(org)
    for place in card.get("entities_places") or []:
        if place:
            entities.append(str(place))
    return entities


def _annotate_translation_uniqueness(
    result: TranslationResult,
    *,
    source_text: str,
    target_lang: str,
    story_card: dict | None = None,
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
        named_entities=_story_card_entities(story_card),
    )
    result.uniqueness_pct = round(verdict.uniqueness_pct, 1)
    result.uniqueness_passed = verdict.passed
    result.uniqueness_reason = verdict.reason
    result.uniqueness_shared = list(getattr(verdict, "shared_samples", []) or [])


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
            # 2026-06-16: было 2048 → тело перевода обрывалось на полуслове
            # у длинных статей (кириллица «тяжелее» в токенах + JSON title+
            # lead+card_lead+body). 4096 покрывает полную статью с запасом.
            kwargs["max_tokens"] = 4096
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
            # 2026-05-12 W1.1: grammar fix first (catch closer to model output),
            # then name normalization (DE→UA transliteration).
            title = _normalize_ukrainian_grammar(_normalize_ukrainian_names(title))
            lead = _normalize_ukrainian_grammar(_normalize_ukrainian_names(lead))
            body = _normalize_ukrainian_grammar(_normalize_ukrainian_names(body))
        title, lead, body = _strip_translation_added_first_names(title, lead, body, source_text, target_lang)
        if target_lang.lower().startswith("ukrain"):
            title = _normalize_ukrainian_grammar(_fix_ukrainian_gender_agreement(_normalize_ukrainian_style(_normalize_ukrainian_title(title))))
            lead = _normalize_ukrainian_grammar(_fix_ukrainian_gender_agreement(_move_ukrainian_source_attribution(_normalize_ukrainian_style(lead))))
            body = _normalize_ukrainian_grammar(_fix_ukrainian_gender_agreement(_move_ukrainian_source_attribution(_normalize_ukrainian_style(body))))
            title, lead, body = _strip_translation_added_first_names(title, lead, body, source_text, target_lang)
            # 2026-05-16 Q-fix: hybrid Latin-Cyrillic words detector + fixer.
            # Применяется LAST в chain'е чтоб catch'ить остатки после всех
            # других normalizers. Confusables substitution + DE→UK transliteration.
            title = _fix_latin_cyrillic_hybrid_words(_normalize_ukrainian_grammar(_fix_ukrainian_gender_agreement(_normalize_ukrainian_style(_normalize_ukrainian_title(_normalize_ukrainian_names(title))))))
            lead = _fix_latin_cyrillic_hybrid_words(_normalize_ukrainian_grammar(_fix_ukrainian_gender_agreement(_move_ukrainian_source_attribution(_normalize_ukrainian_style(_normalize_ukrainian_names(lead))))))
            body = _fix_latin_cyrillic_hybrid_words(_normalize_ukrainian_grammar(_fix_ukrainian_gender_agreement(_move_ukrainian_source_attribution(_normalize_ukrainian_style(_normalize_ukrainian_names(body))))))
            title, lead, body = _repair_ukrainian_structure(title, lead, body)
            warnings = _ukrainian_style_warnings(title, lead, body)
            filler_count = len(warnings)
            filler_samples = warnings[:8]
            # 2026-05-13: filler phrase counter — НЕ блокирует, soft signal.
            phrase_count, phrase_samples = _count_ukrainian_filler_phrases(
                f"{title}\n{lead}\n{body}"
            )
            filler_count += phrase_count
            existing_warning_samples = set(filler_samples)
            for sample in phrase_samples:
                if sample not in existing_warning_samples and len(filler_samples) < 8:
                    filler_samples.append(sample)
                    existing_warning_samples.add(sample)
            # R10 Phase 2 2026-05-14: lemma-based augmentation. Catches
            # morphological variants regex пропускает. Combined в общий
            # style_filler_count, samples deduplicated.
            lemma_count, lemma_samples = _count_filler_lemmas(
                f"{title}\n{lead}\n{body}"
            )
            if lemma_count > 0:
                filler_count += lemma_count
                # Dedupe samples
                existing = set(filler_samples)
                for s in lemma_samples:
                    if s not in existing and len(filler_samples) < 8:
                        filler_samples.append(s)
                        existing.add(s)
        else:
            title = _normalize_non_ukrainian_source_names(title)
            lead = _normalize_non_ukrainian_source_names(lead)
            body = _normalize_non_ukrainian_source_names(body)
            # 2026-05-16 Q-fix EN: soft repair Cyrillic chars before hard error.
            # Earlier code raised ValueError on any Cyrillic — discarded full
            # translation. Now: confusables fix + BGN/PCGN transliteration
            # FIRST, then hard error if STILL contaminated (safety net).
            title = _fix_cyrillic_latin_hybrid_words_en(title)
            lead = _fix_cyrillic_latin_hybrid_words_en(lead)
            body = _fix_cyrillic_latin_hybrid_words_en(body)
            if _english_contains_cyrillic(title, lead, body):
                raise ValueError("english translation contains Cyrillic text")
            if target_lang.lower().startswith("engl") and _english_looks_german(title, lead, body):
                raise ValueError("english translation appears to be German output")
            filler_count, filler_samples = 0, []
        return TranslationResult(
            title=title,
            lead=lead,
            body=body,
            card_lead=card_lead,
            success=True,
            tokens=completion_total_tokens(resp),
            cached_tokens=completion_cached_tokens(resp),
            style_filler_count=filler_count,
            style_filler_samples=filler_samples,
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


# 2026-05-16 Q-fix: Latin↔Cyrillic confusables. AI sometimes outputs hybrid
# tokens like "Бärbel" (Cyrillic Б + Latin ärbel) or "чорнo" (Latin o instead
# of Cyrillic о). Look-alikes substitution + German→Ukrainian transliteration.
_LATIN_TO_CYRILLIC_CONFUSABLES: dict[str, str] = {
    'a': 'а', 'c': 'с', 'e': 'е', 'o': 'о', 'p': 'р', 'x': 'х', 'y': 'у',
    'i': 'і', 'A': 'А', 'B': 'В', 'C': 'С', 'E': 'Е', 'H': 'Н', 'K': 'К',
    'M': 'М', 'O': 'О', 'P': 'Р', 'T': 'Т', 'X': 'Х', 'Y': 'У', 'I': 'І',
    # German umlauts → Ukrainian approximations
    'ä': 'е', 'ö': 'е', 'ü': 'ю', 'Ä': 'Е', 'Ö': 'Е', 'Ü': 'Ю', 'ß': 'сс',
    'é': 'е', 'è': 'е', 'ê': 'е', 'É': 'Е', 'È': 'Е', 'Ê': 'Е',
    'á': 'а', 'à': 'а', 'â': 'а', 'Á': 'А', 'À': 'А', 'Â': 'А',
    'í': 'і', 'ì': 'і', 'î': 'і', 'Í': 'І', 'Ì': 'І', 'Î': 'І',
    'ó': 'о', 'ò': 'о', 'ô': 'о', 'Ó': 'О', 'Ò': 'О', 'Ô': 'О',
    'ú': 'у', 'ù': 'у', 'û': 'у', 'Ú': 'У', 'Ù': 'У', 'Û': 'У',
}

# German→Ukrainian phonetic rules (multi-char first for greedy match).
# Based on DSTU 9112 + common journalistic practice (Spiegel/Zeit name guides).
_DE_TO_UK_DIGRAPHS: list[tuple[str, str]] = [
    ('Sch', 'Ш'), ('sch', 'ш'),
    ('Tsch', 'Ч'), ('tsch', 'ч'),
    ('Ch', 'Х'), ('ch', 'х'),
    ('Ck', 'К'), ('ck', 'к'),
    ('Ph', 'Ф'), ('ph', 'ф'),
    ('Sh', 'Ш'), ('sh', 'ш'),
    ('Th', 'Т'), ('th', 'т'),
    ('Ei', 'Ай'), ('ei', 'ай'),
    ('Ie', 'І'), ('ie', 'і'),
    ('Eu', 'Ой'), ('eu', 'ой'),
    ('Au', 'Ау'), ('au', 'ау'),
]
_DE_TO_UK_SINGLES: dict[str, str] = {
    'A': 'А', 'a': 'а', 'B': 'Б', 'b': 'б', 'C': 'К', 'c': 'к',
    'D': 'Д', 'd': 'д', 'E': 'Е', 'e': 'е', 'F': 'Ф', 'f': 'ф',
    'G': 'Г', 'g': 'г', 'H': 'Г', 'h': 'г', 'I': 'І', 'i': 'і',
    'J': 'Й', 'j': 'й', 'K': 'К', 'k': 'к', 'L': 'Л', 'l': 'л',
    'M': 'М', 'm': 'м', 'N': 'Н', 'n': 'н', 'O': 'О', 'o': 'о',
    'P': 'П', 'p': 'п', 'Q': 'К', 'q': 'к', 'R': 'Р', 'r': 'р',
    'S': 'С', 's': 'с', 'T': 'Т', 't': 'т', 'U': 'У', 'u': 'у',
    'V': 'В', 'v': 'в', 'W': 'В', 'w': 'в', 'X': 'Кс', 'x': 'кс',
    'Y': 'И', 'y': 'и', 'Z': 'Ц', 'z': 'ц',
    'Ä': 'Е', 'ä': 'е', 'Ö': 'Е', 'ö': 'е', 'Ü': 'Ю', 'ü': 'ю',
    'ß': 'сс',
}


def _transliterate_de_word_to_uk(word: str) -> str:
    """Apply German→Ukrainian phonetic transliteration на single word."""
    # Apply digraphs first (longer matches greedy)
    for src, dst in _DE_TO_UK_DIGRAPHS:
        word = word.replace(src, dst)
    # Then single chars
    return ''.join(_DE_TO_UK_SINGLES.get(c, c) for c in word)


# Brands, publishers and abbreviations kept in Latin in Ukrainian copy.
_KEEP_LATIN_TOKENS: frozenset[str] = frozenset({
    'AfD', 'CDU', 'CSU', 'SPD', 'FDP', 'BSW', 'CDU/CSU',
    'NATO', 'EU', 'UN', 'OSZE', 'WHO', 'WTO', 'IWF', 'IMF', 'OECD',
    'USA', 'UK', 'DE', 'FR', 'DDR', 'BRD',
    'BBC', 'CNN', 'ARD', 'ZDF', 'BR24', 'NDR', 'WDR', 'SWR', 'MDR', 'RBB',
    'Süddeutsche', 'Spiegel', 'Welt', 'FAZ', 'Bild', 'Zeit', 'Stern',
    'Reuters', 'AFP', 'dpa', 'AP', 'EPA',
	    'Bundestag', 'Bundesrat',
	    'Kyiv', 'Post', 'Independent', 'Guardian', 'Politico', 'Europe',
	    'Wall', 'Street', 'Journal', 'The', 'New', 'York', 'Times', 'Washington',
	    'Bloomberg',
	    'Kyivpost', 'KyivPost', 'EuroPulse', 'Tagesschau', 'Tagesspiegel', 'ZDFheute', 'Phoenix', 'Handelsblatt',
    'Evonik', 'Truth', 'Social', 'Transparency', 'International',
    'Naftogaz', 'Ukrzaliznytsia', 'Bayerischer', 'Rundfunk',
    'Deutschlandfunk', 'Süddeutsche', 'Zeitung', 'MagentaSport', 'ProSieben',
    '24tv', 'Deutsche', 'Welle', 'Heise', 'TechCrunch', 'Finance', 'Bahn',
    'Golem', 'Linux', 'Flixtrain', 'HateAid', 'Waymo',
    'Apple', 'Google', 'Microsoft', 'Meta', 'OpenAI', 'Tesla', 'X',
    'Samsung', 'Sony', 'Siemens', 'BMW', 'VW', 'Audi', 'Mercedes',
    'SAP', 'Deutsche Bank', 'Commerzbank',
})


def _fix_latin_cyrillic_hybrid_words(text: str) -> str:
    """2026-05-16 Q-fix: detect tokens с Latin chars в Ukrainian context, fix.

    Strategy:
    1. Tokenize text preserving punctuation
    2. For each token decide action:
       - Brand/acronym whitelist → keep
       - Mixed Cyr+Lat → confusables sub OR transliterate
       - Pure Latin word in Cyrillic context → transliterate (German name)
    3. Cyrillic context detection: ≥3 Cyrillic tokens в окрестности (sentence/window)
    """
    if not text:
        return text

    # Never run the transliterator over markup or URLs. Earlier repair passes
    # can leave HTML-like fragments in body text, and a pure Latin URL in a
    # Ukrainian paragraph must not become "гттп://...".
    protected_parts = re.split(r'(<[^>]+>|https?://[^\s<>()]+)', text, flags=re.I | re.U)
    if len(protected_parts) > 1:
        return ''.join(
            part if (part.startswith('<') and part.endswith('>')) or re.match(r'https?://', part, flags=re.I)
            else _fix_latin_cyrillic_hybrid_words(part)
            for part in protected_parts
        )

    def _classify(word: str) -> tuple[bool, bool, int, int]:
        """Returns (has_cyr, has_lat, cyr_count, lat_count)."""
        cyr = sum(1 for c in word if 'Ѐ' <= c <= 'ӿ')
        lat = sum(1 for c in word if ('a' <= c.lower() <= 'z') or c in 'äöüÄÖÜß')
        return (cyr > 0, lat > 0, cyr, lat)

    def _is_alpha_token(t: str) -> bool:
        return bool(t) and any(c.isalpha() for c in t)

    def _is_brand(word: str) -> bool:
        return word in _KEEP_LATIN_TOKENS

    def _is_pure_latin_word(word: str) -> bool:
        """Pure Latin alphabetical (no digits, no Cyrillic)."""
        if not word or not any(c.isalpha() for c in word):
            return False
        for c in word:
            if c.isalpha():
                if not (('a' <= c.lower() <= 'z') or c in 'äöüÄÖÜßéèêíìîáàâóòôúùû'):
                    return False
        return True

    # Tokenize preserving separators
    tokens = re.split(r'(\s+|[.,;:!?()«»"\'\-—–]+)', text)

    # Detect Cyrillic-dominant context (≥30% of alpha tokens are Cyrillic)
    alpha_tokens = [t for t in tokens if _is_alpha_token(t)]
    if not alpha_tokens:
        return text
    cyr_token_count = sum(1 for t in alpha_tokens if _classify(t)[0])
    is_cyrillic_context = cyr_token_count >= max(3, len(alpha_tokens) // 3)

    def _fix_word(word: str) -> str:
        has_cyr, has_lat, cyr_count, lat_count = _classify(word)
        # Pure Cyrillic — nothing to do
        if has_cyr and not has_lat:
            return word
        # Pure Latin in Cyrillic context → check brand whitelist first, иначе transliterate
        if has_lat and not has_cyr:
            if _is_brand(word):
                return word
            # Short uppercase (likely acronym not in whitelist) — keep
            if len(word) <= 4 and word.isupper():
                return word
            # Pure Latin word in Cyrillic context → German→Ukrainian translit
            if is_cyrillic_context and _is_pure_latin_word(word):
                return _transliterate_de_word_to_uk(word)
            return word
        # Mixed Cyr+Lat (hybrid bug)
        if has_cyr and has_lat:
            if _is_brand(word):
                return word
            # Mostly Cyrillic → confusables substitution first
            if cyr_count >= lat_count:
                fixed = ''.join(_LATIN_TO_CYRILLIC_CONFUSABLES.get(c, c) for c in word)
                _, has_lat2, _, _ = _classify(fixed)
                if not has_lat2:
                    return fixed
                return _transliterate_de_word_to_uk(fixed)
            # Mostly Latin with Cyrillic stuck — full transliteration
            return _transliterate_de_word_to_uk(word)
        return word

    return ''.join(_fix_word(t) if _is_alpha_token(t) else t for t in tokens)


def _normalize_ukrainian_names(text: str) -> str:
    cleaned = text
    # 2026-05-12 W1.2: extended dict для DE→UA name transliteration.
    # Раньше gpt-4o-mini генерил «Манюела» (Manuela), «Швезіг» с typos.
    # Whitelist common German political/public figures с canonical UA-form.
    replacements = {
        # Miersch / Söder fixes (legacy)
        "Міерш": "Мірш", "Міерша": "Мірша", "Миерш": "Мірш", "Мерш": "Мірш",
        "Зеєдер": "Зедер", "Зеєдера": "Зедера",
        "Зьодер": "Зедер", "Зьодера": "Зедера",
        "Сьодер": "Зедер", "Сьодера": "Зедера",
        "Зодер": "Зедер", "Зодера": "Зедера",
        # 2026-05-12: common DE first names — typo fix
        "Манюела": "Мануела", "Манюели": "Мануели", "Манюелу": "Мануелу",
        "Манюелі": "Мануелі", "Манюелою": "Мануелою",
        "Барбель": "Бербель",  # Bas / Wagenknecht — capital Bärbel
        "Аннелі": "Аннелізе",  # rare typo
        # Schwesig variants
        "Швезиг": "Швезіг", "Швесиг": "Швезіг", "Швесіг": "Швезіг",
        # Pistorius variants
        "Пісторіус": "Пісторіус",  # canonical
        "Пісторюс": "Пісторіус", "Пістореус": "Пісторіус",
        # Habeck
        "Хабек": "Габек", "Хабека": "Габека",
        # Baerbock
        "Беєрбок": "Бербок", "Берьок": "Бербок",
        # Faeser
        "Феєсер": "Фезер", "Феєсера": "Фезера",
        # Lauterbach
        "Лаутербах": "Лаутербах",
        # Klingbeil
        "Клінгбайл": "Клінгбайль",
        # Lang (Ricarda)
        "Лянг": "Ланг",
        # 2026-05-13 v21: institutional names — Abraham Accords canonical UA
        "Абрамські угоди": "Угоди Авраама",
        "Абрамських угод": "Угод Авраама",
        "Абрамським угодам": "Угодам Авраама",
        "Авраамські угоди": "Угоди Авраама",
        "Авраамських угод": "Угод Авраама",
        # FC Bayern brand fix — Bayern (Bavaria) vs Bayer (Leverkusen)
        "Байєр Мюнхен": "Баварія Мюнхен", "Байер Мюнхен": "Баварія Мюнхен",
        "ФК Байєр Мюнхен": "Баварія Мюнхен", "ФК Байер Мюнхен": "Баварія Мюнхен",
        # Common typos in toponyms
        "Мекленбург-Передньої Померанії": "Мекленбург-Передньої Померанії",
    }
    for src, dst in replacements.items():
        cleaned = re.sub(rf"(?<!\w){re.escape(src)}(?!\w)", dst, cleaned, flags=re.U)
    cleaned = re.sub(r"\bSPD\b", "СДПН", cleaned)
    cleaned = re.sub(r"\bМірш\s*\((?:Miersch)\)", "Мірш", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bЗедер\s*\((?:Söder|Soeder)\)", "Зедер", cleaned, flags=re.I | re.U)
    return _normalize_ukrainian_brand_names(cleaned)


def _normalize_ukrainian_brand_names(text: str) -> str:
    """Fix media/brand names that the model phonetically transliterates.

    Ukrainian copy should not contain broken half-transliterations such as
    "Киів Пост" or "ОйроПулсе". Keep globally recognized publication and
    company names in their canonical form, or use established Ukrainian names.
    """
    if not text:
        return text
    cleaned = text
    replacements = {
        "Киів Пост": "Kyiv Post",
        "Київ Пост": "Kyiv Post",
        "Кіїв Пост": "Kyiv Post",
        "Киівпост": "Kyiv Post",
        "Київпост": "Kyiv Post",
        "Кіївпост": "Kyiv Post",
        "Kyivpost": "Kyiv Post",
        "KyivPost": "Kyiv Post",
        "Киів Індепендент": "Kyiv Independent",
        "Київ Індепендент": "Kyiv Independent",
        "Украінска Правда": "Українська правда",
        "Украінска правда": "Українська правда",
        "Українска Правда": "Українська правда",
        "Українска правда": "Українська правда",
        "Українська Правда": "Українська правда",
        "Украінська Правда": "Українська правда",
        "Укрінска Правда": "Українська правда",
        "Укрінська Правда": "Українська правда",
        "Гуардіан": "The Guardian",
        "Нью-Йорк Таймс": "The New York Times",
        "Нью Йорк Таймс": "The New York Times",
        "Вашингтон Пост": "The Washington Post",
        "Рейтерс": "Reuters",
        "Блумберг": "Bloomberg",
        "Бі-бі-сі": "BBC",
        "Бі Бі Сі": "BBC",
        "Політіко Ойропе": "Politico Europe",
        "Політіко Європе": "Politico Europe",
        "Політіко": "Politico",
        "ОйроПулсе": "EuroPulse",
        "Ойропулсе": "EuroPulse",
        "Ойро Пулсе": "EuroPulse",
        "Ойро-Пулсе": "EuroPulse",
        "ойро-пулсе": "EuroPulse",
        "ойропулсе": "EuroPulse",
        "Спігел": "Spiegel",
        "Тагесшау": "Tagesschau",
        "Тагесспігел": "Tagesspiegel",
        "Тагесспігель": "Tagesspiegel",
        "Тагесшпігел": "Tagesspiegel",
        "Тагесшпігель": "Tagesspiegel",
        "Цдфгойте": "ZDFheute",
        "ЗДФгойте": "ZDFheute",
        "Фоенікс": "Phoenix",
        "Трут Сокіал": "Truth Social",
        "Валл Стреет": "Wall Street",
        "Валл Стріт": "Wall Street",
        "Валл-стріт": "Wall Street",
        "Валл-Стріт": "Wall Street",
        "Волл Стріт": "Wall Street",
        "Волл-стріт": "Wall Street",
        "Волл-Стріт": "Wall Street",
        "Уолл Стріт": "Wall Street",
        "Уолл-стріт": "Wall Street",
        "Уолл-Стріт": "Wall Street",
        "Ганделсблатт": "Handelsblatt",
        "Евонік": "Evonik",
        "Баиерішер Рундфунк": "Bayerischer Rundfunk",
        "Транспаренки Інтернатіонал": "Transparency International",
        "Трансперенсі Інтернешнл": "Transparency International",
        "Нафтогац": "Нафтогаз",
        "Укрцаліцнитсіа": "Укрзалізниця",
        "Укрзалізниціа": "Укрзалізниця",
        "Дойтше Багн": "Deutsche Bahn",
        "Дойтше Велле": "Deutsche Welle",
        "Дойтшландфунк": "Deutschlandfunk",
        "Дойчландфунк": "Deutschlandfunk",
        "Süddeutsche Цайтунг": "Süddeutsche Zeitung",
        "Зюддойче Цайтунг": "Süddeutsche Zeitung",
        "Зюддойче Zeitung": "Süddeutsche Zeitung",
        "24тв": "24tv",
        "24ТВ": "24tv",
        "МагентаСпорт": "MagentaSport",
        "Магента Спорт": "MagentaSport",
        "ПроСібен": "ProSieben",
        "Про Сібен": "ProSieben",
    }
    for src, dst in replacements.items():
        cleaned = re.sub(rf"(?<!\w){re.escape(src)}(?!\w)", dst, cleaned, flags=re.U)
    cleaned = re.sub(r"\bУкра[їі]нс(?:ь)?ка\s+правда\b", "Українська правда", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bKyiv\s*post\b", "Kyiv Post", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bКи[іїі]в\s*пост\b", "Kyiv Post", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\bОйро[-\s]?пулсе\b", "EuroPulse", cleaned, flags=re.I | re.U)
    cleaned = _strip_broken_ukrainian_urls(cleaned)
    cleaned = re.sub(r"\s*https?://www\.(?:ойро[-\s]?пулсе|ойропулсе)[^\s<)]+", "", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"\s*:\s*(</p>)", r"\1", cleaned, flags=re.U)
    # If the model already dropped the "Politico" half and left only
    # "Europe" transliterated as a source name, restore the publication.
    cleaned = re.sub(
        r"\b(за даними|як повідомляє|повідомляє|з посиланням на)\s+Ойропе\b",
        r"\1 Politico Europe",
        cleaned,
        flags=re.I | re.U,
    )
    return cleaned


def _strip_broken_ukrainian_urls(text: str) -> str:
    """Remove URLs that were already phonetically transliterated by the model.

    These are not valid links and should not reach rendered posts. Keep the
    surrounding sentence readable by removing only the parenthetical/link token.
    """
    if not text:
        return text
    cleaned = re.sub(r"\s*\((?:гттпс?|гттп|хттпс?|хттп)://[^)]*\)", "", text, flags=re.I | re.U)
    cleaned = re.sub(r"\s*(?:гттпс?|гттп|хттпс?|хттп)://[^\s<)]+", "", cleaned, flags=re.I | re.U)
    cleaned = re.sub(
        r"<p\b[^>]*>\s*</p>",
        "",
        cleaned,
        flags=re.I | re.S | re.U,
    )
    return cleaned


def _normalize_html_block_spacing(text: str) -> str:
    if not text or "<" not in text:
        return text
    cleaned = re.sub(r"(</p>)\s*(<h[2-6]\b)", r"\1\n\n\2", text, flags=re.I | re.U)
    cleaned = re.sub(r"(</h[2-6]>)\s*(<p\b)", r"\1\n\n\2", cleaned, flags=re.I | re.U)
    cleaned = re.sub(r"(</p>)\s*(<p\b)", r"\1\n\n\2", cleaned, flags=re.I | re.U)
    return cleaned


_UK_INVALID_VERB_FIXES = {
    # 2026-05-12 W1.1: gpt-4o-mini / deepseek-chat генерят invalid Ukrainian
    # verb forms (закликаій/закликалий/стикаєтьсій/піднімалосє). Это не существующие
    # словоформы — model fails суффикс. Whitelist replace.
    "закликаій": "закликала", "закликалий": "закликала", "закликаліз": "закликала",
    "стикаєтьсій": "стикається", "стикаєтьсі": "стикається",
    "піднімалосє": "піднімалося", "піднімалосіі": "піднімалося",
    "розповідаії": "розповідає", "розповідаіі": "розповідає",
    "обговорюіі": "обговорює", "обговорюіт": "обговорює",
    "відмовляіт": "відмовляє", "вирішуіт": "вирішує",
    "оприлюдниій": "оприлюднила", "опубліковаій": "опублікувала",
    "повідомиій": "повідомила", "заявиій": "заявила",
    "пояснилоій": "пояснила", "розповіій": "розповіла",
    # 2026-05-13: новые наблюдаемые в проде варианты -уій (verb-stem + invalid suffix)
    "спрощуій": "спрощує", "ускладнюій": "ускладнює",
    "забезпечуій": "забезпечує", "продовжуій": "продовжує",
    "вимагаій": "вимагає", "пропонуій": "пропонує",
    "очікуій": "очікує", "розглядаій": "розглядає",  # dead entry "розгляддаій" удалён v21
    "посилюій": "посилює", "підтримуій": "підтримує",
    "атакуій": "атакує", "звинувачуій": "звинувачує",
    "показуій": "показує", "реформуій": "реформує",
    "представилий": "представила", "помилкоє": "помилкове",
}


def _normalize_ukrainian_grammar(text: str) -> str:
    """2026-05-12 W1.1 — post-validation на invalid UA verb endings.

    gpt-4o-mini и deepseek-chat иногда генерят суффикс-ломаные слова типа
    `закликалий` (должно `закликала`), `стикаєтьсій` (`стикається`). Это
    не typo и не legitimate dialect — это model failure. Hardcoded replace.
    Если future regression — добавлять в _UK_INVALID_VERB_FIXES.
    """
    cleaned = text
    for src, dst in _UK_INVALID_VERB_FIXES.items():
        cleaned = re.sub(rf"\b{re.escape(src)}\b", dst, cleaned, flags=re.U | re.I)
    # 2026-05-13 W1.1-hotfix: removed broad-stem regex `([а-яіїєґ]{3,})(алий|авій|авіт)\b`
    # — corrupted legitimate masculine adjectives "тривалий", "кривавій", "відсталий",
    # "довготривалий" → feminine "тривала". Lambda branches also both returned 'ала'.
    # Whitelist-only approach (_UK_INVALID_VERB_FIXES) — false negatives < false positives.
    # Forms ending «-ьсій» (instead of -ься) — narrow, safe
    cleaned = re.sub(r"\b([а-яіїєґ]{3,})ьсій\b", lambda m: m.group(1) + 'ься', cleaned, flags=re.U | re.I)
    # Forms ending «-осє» (instead of -ося) — narrow, safe
    cleaned = re.sub(r"\b([а-яіїєґ]{3,})осє\b", lambda m: m.group(1) + 'ося', cleaned, flags=re.U | re.I)
    return cleaned


# 2026-05-16 Q-fix EN side: Cyrillic→Latin transliteration + hybrid token fixer.
# Same pattern как UK side, но обратное направление. AI sometimes leaves
# Cyrillic chars в English output, или производит hybrid tokens.

# Cyrillic→Latin confusables (visual look-alikes). For mostly-Latin token
# with 1-2 Cyrillic chars stuck — direct substitution.
_CYRILLIC_TO_LATIN_CONFUSABLES: dict[str, str] = {
    'а': 'a', 'в': 'v', 'с': 'c', 'е': 'e', 'о': 'o', 'р': 'p', 'х': 'x', 'у': 'y',
    'і': 'i', 'А': 'A', 'В': 'V', 'С': 'C', 'Е': 'E', 'Н': 'H', 'К': 'K',
    'М': 'M', 'О': 'O', 'Р': 'P', 'Т': 'T', 'Х': 'X', 'У': 'Y', 'І': 'I',
}

# Full Cyrillic → Latin transliteration (BGN/PCGN Ukrainian + GOST 7.79 Russian).
# Used for full-word Cyrillic tokens stuck в English text. Digraphs first.
_UK_RU_TO_EN_DIGRAPHS: list[tuple[str, str]] = [
    ('Щ', 'Shch'), ('щ', 'shch'),
    ('Ж', 'Zh'), ('ж', 'zh'),
    ('Ч', 'Ch'), ('ч', 'ch'),
    ('Ш', 'Sh'), ('ш', 'sh'),
    ('Х', 'Kh'), ('х', 'kh'),
    ('Ц', 'Ts'), ('ц', 'ts'),
    ('Ю', 'Yu'), ('ю', 'yu'),
    ('Я', 'Ya'), ('я', 'ya'),
    ('Є', 'Ye'), ('є', 'ye'),
    ('Ї', 'Yi'), ('ї', 'yi'),
    ('Й', 'Y'), ('й', 'y'),
]
_UK_RU_TO_EN_SINGLES: dict[str, str] = {
    'А': 'A', 'а': 'a', 'Б': 'B', 'б': 'b', 'В': 'V', 'в': 'v',
    'Г': 'H', 'г': 'h',  # UK pronunciation (RU = G но UK = H)
    'Ґ': 'G', 'ґ': 'g',
    'Д': 'D', 'д': 'd', 'Е': 'E', 'е': 'e',
    'З': 'Z', 'з': 'z', 'И': 'Y', 'и': 'y',  # UK 'и' = 'y' (RU 'и' = 'i')
    'І': 'I', 'і': 'i', 'К': 'K', 'к': 'k', 'Л': 'L', 'л': 'l',
    'М': 'M', 'м': 'm', 'Н': 'N', 'н': 'n', 'О': 'O', 'о': 'o',
    'П': 'P', 'п': 'p', 'Р': 'R', 'р': 'r', 'С': 'S', 'с': 's',
    'Т': 'T', 'т': 't', 'У': 'U', 'у': 'u', 'Ф': 'F', 'ф': 'f',
    'Ы': 'Y', 'ы': 'y',  # RU specific
    'Э': 'E', 'э': 'e',  # RU specific
    'Ь': "'", 'ь': "'", 'Ъ': '"', 'ъ': '"',
}


def _transliterate_cyrillic_word_to_en(word: str) -> str:
    """Apply Cyrillic→Latin (BGN/PCGN UA + GOST RU) transliteration to word."""
    for src, dst in _UK_RU_TO_EN_DIGRAPHS:
        word = word.replace(src, dst)
    return ''.join(_UK_RU_TO_EN_SINGLES.get(c, c) for c in word)


def _fix_cyrillic_latin_hybrid_words_en(text: str) -> str:
    """2026-05-16 Q-fix EN: detect tokens с Cyrillic chars в English context.

    Mirror logic _fix_latin_cyrillic_hybrid_words for UK side:
    1. Tokenize preserving punctuation
    2. For each alpha token:
       - Pure Latin → unchanged
       - Pure Cyrillic в Latin context → transliterate via BGN/PCGN
       - Mixed → confusables substitution first, fallback to full translit
    3. Latin context = ≥30% Latin tokens OR ≥3 Latin tokens
    """
    if not text:
        return text

    def _classify(word: str) -> tuple[bool, bool, int, int]:
        cyr = sum(1 for c in word if 'Ѐ' <= c <= 'ӿ')
        lat = sum(1 for c in word if ('a' <= c.lower() <= 'z') or c in 'äöüÄÖÜßéèêíìîáàâóòôúùû')
        return (cyr > 0, lat > 0, cyr, lat)

    def _is_alpha_token(t: str) -> bool:
        return bool(t) and any(c.isalpha() for c in t)

    tokens = re.split(r'(\s+|[.,;:!?()«»"\'\-—–]+)', text)

    # Detect Latin-dominant context
    alpha_tokens = [t for t in tokens if _is_alpha_token(t)]
    if not alpha_tokens:
        return text
    lat_token_count = sum(1 for t in alpha_tokens if _classify(t)[1])
    is_latin_context = lat_token_count >= max(3, len(alpha_tokens) // 3)

    def _fix_word(word: str) -> str:
        has_cyr, has_lat, cyr_count, lat_count = _classify(word)
        # Pure Latin — unchanged
        if has_lat and not has_cyr:
            return word
        # Pure Cyrillic в Latin context → transliterate
        if has_cyr and not has_lat:
            if is_latin_context:
                return _transliterate_cyrillic_word_to_en(word)
            return word
        # Mixed
        if has_cyr and has_lat:
            # Mostly Latin → confusables substitution
            if lat_count >= cyr_count:
                fixed = ''.join(_CYRILLIC_TO_LATIN_CONFUSABLES.get(c, c) for c in word)
                _, _, cyr_after, _ = _classify(fixed)
                if cyr_after == 0:
                    return fixed
                # Still has Cyrillic — full transliteration
                return _transliterate_cyrillic_word_to_en(fixed)
            # Mostly Cyrillic — full transliteration
            return _transliterate_cyrillic_word_to_en(word)
        return word

    return ''.join(_fix_word(t) if _is_alpha_token(t) else t for t in tokens)


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


def _english_looks_german(*parts: str) -> bool:
    text = "\n".join(parts)
    if not text.strip():
        return False
    german_markers = re.findall(
        r"\b(?:der|die|das|den|dem|des|und|oder|aber|nicht|mit|auf|für|über|unter|"
        r"Grönland|Dänemark|Verteidigungsabkommen|Investitionsabkommen|Bericht|"
        r"berichtet|zufolge|gegenüber|Insel|Forderungen|Amerikaner)\b",
        text,
        flags=re.I | re.U,
    )
    words = re.findall(r"\b[A-Za-zÄÖÜäöüß]{2,}\b", text, flags=re.U)
    if not words:
        return False
    return len(german_markers) >= 8 and (len(german_markers) / max(1, len(words))) >= 0.04


def _translation_error_retryable(error: str) -> bool:
    lowered = (error or "").lower()
    return (
        "appears to be german" in lowered
        or "editorial style check failed" in lowered
        or "empty" in lowered
        or "invalid json" in lowered
    )


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
    text = re.sub(
        r"\bсвоє\s+дипломатичне\s+персонал\b",
        "свій дипломатичний персонал",
        text,
        flags=re.IGNORECASE | re.UNICODE,
    )

    masc_nouns = [
        "парад", "звіт", "саміт", "баланс", "вечір", "конгрес",
        "процес", "форум", "проєкт", "проект", "комітет", "уряд",
        "вибір", "закон", "референдум", "удар", "наступ", "виступ",
        "доступ", "момент", "захід", "знак", "опис", "обмін",
        "персонал",
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
    cleaned = _normalize_ukrainian_brand_names(cleaned)
    # Model sometimes glues sentence boundaries inside one paragraph:
    # "років.Слідчі", "критики.Рішення". This is always a typography bug.
    cleaned = re.sub(r"([.!?])(?=[А-ЯІЇЄҐA-Z])", r"\1 ", cleaned, flags=re.U)
    cleaned = _normalize_html_block_spacing(cleaned)
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


# 2026-05-13 anti-filler detector. AI gpt-4o-mini систематически вставляет
# meta-filler фразы в украинский перевод, хотя prompt-правила (rule 75-78)
# их явно запрещают. Detector не блокирует — только counts, потом soft warning.
# Если 3+ фраз в одном переводе — операторская проверка (PHP gate).
_UK_FILLER_PATTERNS = [
    # Прямые запрещённые формулировки (от prompt'а)
    r"\bможливі наслідки\b",
    r"\bце рішення може вплинути\b",
    r"\bце може вплинути\b",
    r"\bофіційне підтвердження поки що відсутнє\b",
    r"\bподальші деталі поки не відомі\b",
    r"\bце піднімає питання\b",
    r"\bзалишається спостерігати\b",
    r"\bекспертам? вбачають? у цьому\b",
    r"\bце свідчить про\b",
    r"(?<!\sяк )\bце підкреслю(є|ють)\b",  # "як підкреслює виробник" = legit attribution
    r"\bце відображає\b",
    r"\bце вказує на\b",
    r"\bце демонстру(є|ють)\b",
    r"\bце показу(є|ють)\b",
    # 2026-05-13 v21: убран \b до "ситуація" — Cyrillic + \b edge давал silent failure.
    # Worker auditor: "Ситуація показує" в 10864 не матчился, хотя должен.
    r"(?:^|[\s,])[Сс]итуація показу(є|ють)\b",
    r"\bдебати .* показу(ють|є)\b",
    r"\bзростаюч(е|у) занепокоєння\b",
    r"\bзростаючу стурбованість\b",
    # 2026-05-13 v21: "викликає занепокоєння" с named entity — legit (e.g. "обстріли
    # викликають занепокоєння міжнародних організацій"). Ловим только когда после
    # неё нет конкретного субъекта (anaphoric usage). Heuristic: следом просто
    # ".", end of sentence, или "що".
    r"\bвиклика(є|ють)?\s+занепокоєння(?=\s*[.,;:]|\s+що|\s*$)",
    r"\bвиклика(в|ли|ло)\s+занепокоєння\b",  # past tense — added v21
    r"\bу зв['ʼ]язку з цим\b",
    r"\bз огляду на це\b",
    r"\bна тлі цього\b",
    r"\bв контексті цього\b",
    # Generic-too-generic meta-comments (rule 80 — final paragraph)
    r"\bекспертами? попереджа(ють|є) про\b",
    r"\bекспертами? застеріга(ють|є)\b",
    r"\bаналітики (бачать|очікують|прогнозують)\b",
    # Bureaucratic calques от prompt'а
    r"\bвбача(є|ють)? потребу\b",
    r"\bвійськов(а|у)\s+здатність\b",  # calque на "military capability"
    # 2026-05-13 v21 — РУ→UK калька «на фоне» (production hit в 10889).
    # Натуральное украинское: «на тлі» (рядом «на тлі цього» = filler, но
    # «на фоні» — это уже RU stylistic borrowing, ловим всегда).
    r"\bна фоні\b",
    # 2026-05-13 v21 — generic finale formula (10906 в проде).
    r"\b(поточні події|ці події|ця ситуація)\s+підкреслю(є|ють)\b",
    # 2026-05-13 v21 — vague demonstrative anaphora без factual content (4+ hits в выборке).
    r"\bці\s+(події|атаки|заходи|практики|обставини|тенденції)\s+(показу|вказу|свідча|підкреслю)",
    # 2026-05-13 v21 — narrative cliché.
    r"\bце вже\s+(не\s+)?(перший|другий|третій|четвертий|сотий)\s+(раз|випадок|спроба|інцидент)\b",
    # 2026-05-13 v21 — Newspeak / hyperbolic framing (10914 hit).
    r"\bісторичн\w+\s+(прорив|момент|подія|зустріч)\b",
    # 2026-05-13 v21 — quantifier filler (production: "все більше під тиском", "все більше людей").
    r"\bвсе більше\s+(під|людей|компаній|випадків|загрожує|стає)",
    # 2026-05-13 v21 — Wasserrohrbruch / другие compound noun calque issues — placeholder.
]


def _count_ukrainian_filler_phrases(text: str) -> tuple[int, list[str]]:
    """Returns (count, sample_phrases). Operates case-insensitive Unicode-aware."""
    if not text:
        return 0, []
    samples = []
    total = 0
    for pat in _UK_FILLER_PATTERNS:
        matches = re.findall(pat, text, re.I | re.U)
        if matches:
            total += len(matches)
            if len(samples) < 5:
                samples.append(matches[0] if isinstance(matches[0], str) else " ".join(matches[0]) if isinstance(matches[0], tuple) else str(matches[0]))
    return total, samples


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
