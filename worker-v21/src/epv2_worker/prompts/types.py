"""Type modules — structural specifications per KIND_SPECS.

Each module describes the STRUCTURE of the article: length, sections,
attribution density, opening and closing requirements. It does NOT
describe stylistic flavour (vocabulary, tone) — that's the rubric's job.

Keys must match EPV2_Content_Kinds constants exactly.
"""


BREAKING_ALERT = """TYP: breaking_alert — Eilmeldung, sofortige Veröffentlichung.

Aufbau:
- Title: 40–70 Zeichen, beginnt mit „Eilmeldung:" oder „+++". Nur Kernfakt.
- Lead: 1 Satz. Was passiert ist. Quelle namentlich.
- Body: 80–300 Zeichen. 1–2 Absätze.

Struktur des Body:
- Absatz 1: Erweiterung um WANN und WO, falls nicht im Lead.
- Absatz 2 (optional): erste Reaktion oder Hinweis auf Folgemeldungen.

Quellen: 1 Quelle ist OK (definitionsgemäß breaking).

Ton: höchste Dringlichkeit, knapp, faktisch. Keine Spekulation, kein Kontext-Ausbau, keine Hintergrundgeschichte.

Was zu vermeiden:
- Spekulation über Folgen, Motive, Reaktionen anderer Akteure.
- Zitate die nicht wörtlich im Original stehen.
- Echo-Block aus eigenen Materialien (für Eilmeldungen nicht passend).

Hinweis: Diese Meldung kann später durch news_article promoted werden, wenn das Thema sich entwickelt — das ist ein separater Pipeline-Schritt, nicht hier.
"""


NEWS_BRIEF = """TYP: news_brief — Kurzmeldung.

Aufbau:
- Title: 50–80 Zeichen.
- Lead: 1–2 Sätze. Kern der Meldung. Quelle namentlich.
- Body: 700–1100 Zeichen (Pflicht-Mindestmass: 700). Drei bis vier kurze Absätze.

Struktur des Body:
- Absatz 1: Erweiterung des Leads — was/wo/wann mit zusätzlichem Detail aus dem Dossier (eine konkrete Zahl, eine Akteurs-Funktion oder eine Ortsangabe, die im Lead noch nicht steht).
- Absatz 2: ein weiteres Faktum aus der Primärquelle — Reaktion, Zahl, Vergleich oder kurzer Hintergrund.
- Absatz 3: Sekundärquelle namentlich — was diese hinzufügt (anderer Winkel, ergänzende Zahl, eigenständige Stimme).
- Absatz 4 (optional): Echo-Block „Europulse berichtete zuvor …" — nur wenn passendes Material vorhanden ist.

Quellen: 1 reicht editorisch, aber wenn ≥ 2 vorhanden, IMMER beide nennen — die zweite ist die strukturelle Berechtigung für Absatz 3.

Längen-Disziplin (wichtig):
- Unter 700 Zeichen ist der Body NICHT akzeptabel — er fällt aus Discover/Top-Stories und wird sichtbar dünn.
- Nicht künstlich aufblähen: KEINE Floskeln, KEINE Wiederholung des Leads, KEINE Spekulation. Wenn das Dossier die 700 Zeichen nicht hergibt, FÜGE ein konkretes Detail aus den Sekundärquellen hinzu (Reaktion, Zahl, Zitat-Splitter < 15 Wörter).

Was zu vermeiden:
- Tiefer Kontext, Analyse, mehrere ausgebaute Reaktionen — das ist news_article.
- Zitate länger als 15 Wörter (sonst news_article).
- Aufbau zur Geschichte ausweiten („Es begann vor Jahren …").
"""


NEWS_ARTICLE = """TYP: news_article — vollständige Nachricht (Standard-Format).

Aufbau:
- Title: 50–80 Zeichen, primäres Schlagwort früh, kein Clickbait.
- Lead: 1–2 Sätze. Wer, was, wann, wo. Quelle namentlich genannt.
- Body: 600–1500 Zeichen. Vier bis sieben kurze Absätze.

Struktur des Body:
- Absatz 1: Erweiterung des Leads (Kontext der Tat/Aussage, weitere Akteure).
- Absatz 2: Reaktion oder Stellungnahme (zitierte Person mit voller Funktion).
- Absatz 3: Hintergrund oder Einordnung (was war vorher, was bedeutet das).
- Absatz 4 (optional): Folgen oder nächste Schritte (nur wenn im Original/Dossier).
- Letzter Absatz (echo): „Europulse berichtete zuvor über …" — max. 1 Satz, mit Querverweis. Nur falls passendes EuroPulse-Material vorhanden ist.

Quellen: mindestens 2. Jede zusätzliche Quelle wird beim Einbringen ihres Faktums namentlich genannt.

Was zu vermeiden:
- Listen-Stil (Aufzählungen mit •/-) im Body.
- „Es bleibt abzuwarten" / „Die Entwicklung lässt sich nicht voraussagen" — Floskeln ohne Inhalt.
"""


EXTENDED_NEWS = """TYP: extended_news — ausführliche Nachricht mit Hintergrund.

Aufbau:
- Title: 60–90 Zeichen.
- Lead: 2 Sätze. Kerngeschehen + zentraler Kontext-Punkt. Quelle namentlich.
- Body: 1500–2500 Zeichen. Sechs bis neun Absätze.

Struktur des Body:
- Absätze 1–2: detaillierte Schilderung des Hauptereignisses. Wer, was, wann, wo, wie.
- Absätze 3–4: Reaktionen — mindestens zwei verschiedene Stimmen mit voller Funktion.
- Absätze 5–6: Hintergrund / Einordnung. Was war vorher, welche Linie, welche Vergleiche.
- Absatz 7 (optional): Folgen / Ausblick (nur faktenbasiert, keine Spekulation).
- Letzter Absatz (echo): „Europulse berichtete zuvor …" mit Querverweis.

Quellen: mindestens 2, idealerweise 3+. Jede Zusatzquelle namentlich genannt beim Einbringen ihres Faktums.

Was zu vermeiden:
- Editoriale Wertung („zu Recht", „bedauerlicherweise") — das ist analysis-Territorium.
- Mehr als zwei Sätze pro Absatz ohne Punkt — Lesbarkeit leidet.
"""


ANALYSIS = """TYP: analysis — analytischer Hintergrund-Artikel.

Aufbau:
- Title: 60–100 Zeichen, oft Frage- oder These-Form („Warum X scheitert" / „Was hinter Y steckt").
- Lead: 2–3 Sätze. These oder zentrale Frage des Stücks. Klares Signal: dies ist Analyse, nicht Nachricht.
- Body: 2500+ Zeichen. Zehn bis fünfzehn Absätze.

Struktur des Body:
- These-Setup (1–2 Absätze): Was ist das Phänomen / Ereignis, das eingeordnet wird.
- Beweisführung (4–7 Absätze): Mehrere Belege aus verschiedenen Quellen. Jede Quelle namentlich. Belege können sein: Zitate, Zahlen, historische Vergleiche, Stimmen von Beteiligten.
- Gegenstimme (1–2 Absätze): bewusst eine alternative Lesart anführen, mit Quelle. Analyse ohne Gegenposition wirkt einseitig.
- Schlussfolgerung (1–2 Absätze): faktenbasierte Folgerung. Keine Vorhersage ohne Beleg, kein „eines steht fest".

Quellen: mindestens 4 verschiedene Quellen für eine seriöse Analyse. 5–8 ist normal.

Ton:
- Nachdenklich, klar argumentiert. Eigene Stimme erlaubt aber moderat — keine pure Meinungsspalte.
- Hypothesen werden als solche markiert: „Das deutet darauf hin, dass …", nicht als Fakten.

Was zu vermeiden:
- Persönliche Pronomen („ich", „wir") — das wäre opinion.
- Schlussfolgerungen ohne Beleg — jede These braucht eine Quelle.
"""


FEATURE = """TYP: feature — journalistische Reportage / Hintergrund-Story.

Aufbau:
- Title: 50–90 Zeichen, oft narrativ („Das Leben nach dem Beben in Lwiw").
- Lead: 2–3 Sätze. Szenischer Einstieg — eine Person, ein Ort, ein Moment. Wirkt wie Erzählung, ist aber faktenbasiert.
- Body: 2000+ Zeichen, oft 3000–5000. Sieben bis fünfzehn Absätze.

Struktur des Body:
- Szenische Eröffnung (1–2 Absätze): konkrete Person, Ort, Detail. Eingang in das größere Thema.
- Hauptdarsteller / -orte (3–5 Absätze): wer ist beteiligt, was haben sie zu sagen. Direkte Zitate bevorzugt. Volle Namen, Berufe, Funktionen.
- Größerer Kontext (3–5 Absätze): Daten, Statistiken, historische Linien, Vergleiche.
- Spannung / Konflikt (2–3 Absätze): wer ist dafür, wer dagegen, was steht auf dem Spiel.
- Schlussbild (1 Absatz): Rückkehr zur Eröffnung oder zu einer Person — ein Satz, der nachklingt.

Quellen: mindestens 5. Reportagen leben von Stimmenvielfalt. Nutze interviewte Personen, Experten, Statistiken, historische Quellen.

Ton:
- Erzählend, mit Atmosphäre. Sinnliche Details (was war zu sehen, zu hören).
- Faktenbasiert — keine erfundenen Szenen, keine ausgemalten Gefühle, die nicht explizit benannt wurden.

Was zu vermeiden:
- „Es war ein sonniger Tag" wenn nicht im Original — keine ausgedachte Atmosphäre.
- Klischees: „Tränen in den Augen", „ein Lächeln huschte über sein Gesicht".
"""


SPORT_RESULT = """TYP: sport_result — Spielergebnis / Wettkampf-Bericht.

Aufbau:
- Title: 40–70 Zeichen, oft Format „Mannschaft A schlägt Mannschaft B 3:1" oder „Athlet gewinnt Marathon X".
- Lead: 1–2 Sätze. Wer hat gegen wen, mit welchem Ergebnis, wo / wann.
- Body: 200–500 Zeichen. 2–3 Absätze.

Struktur des Body:
- Absatz 1: Schlüsselszenen — wer hat das Tor / den Punkt erzielt, in welcher Minute.
- Absatz 2: Tabellenstand / Turnierkontext — wo steht die Mannschaft jetzt, was bedeutet das Ergebnis.
- Absatz 3 (optional): kurzes Zitat eines Beteiligten (Trainer, Spieler), nur wenn im Original.

Quellen: 1 ist OK (Sportergebnisse haben oft nur einen primären Berichterstatter).

Ton: faktisch, präzise. Sportliche Begeisterung erlaubt aber dezent.

Was zu vermeiden:
- Sport-Klischees: „packender Schlagabtausch", „Krimi bis zum Schluss".
- Vorhersagen über zukünftige Spiele.
"""


OBITUARY = """TYP: obituary — Nachruf.

Aufbau:
- Title: 40–80 Zeichen, oft Format „Name (Alter) gestorben" oder „Trauer um Name".
- Lead: 2 Sätze. Wer ist gestorben, in welchem Alter, woran (wenn bekannt). Wo / wann. Quelle namentlich.
- Body: 400+ Zeichen. 4–7 Absätze.

Struktur des Body:
- Absatz 1: kurzer biografischer Abriss (geboren, herkunft, prägende Stationen).
- Absatz 2: berufliche / öffentliche Bedeutung — wofür war die Person bekannt.
- Absatz 3: zentrale Werke / Leistungen / öffentliche Rollen.
- Absatz 4: Reaktionen — wer trauert, wer gedenkt (Politiker, Kollegen, Familie). Mit Zitaten wenn im Original.
- Absatz 5 (optional): Vermächtnis — was bleibt.

Quellen: mindestens 3 (Nachrufe sind oft viel-quellig: Originalmeldung + offizielle Reaktionen + biografische Hintergründe).

Ton: respektvoll, ruhig, würdigend. Kein Boulevard-Stil.

Was zu vermeiden:
- Spekulationen über Todesursache, wenn nicht offiziell bekannt.
- Süßliche Phrasen („für immer in unseren Herzen").
- Privatdetails, die nicht im Original / öffentlich bekannt sind.
"""


INTERVIEW = """TYP: interview — Q&A-Format mit interviewter Person.

Aufbau:
- Title: 50–90 Zeichen, oft Format „Name: ‚zentrale Aussage'".
- Lead: 2–3 Sätze. Wer ist die interviewte Person, ihre Rolle, der Anlass des Interviews. Wer hat das Interview geführt (das Quellmedium).
- Body: 4000+ Zeichen. 8–15 Frage-Antwort-Paare.

Struktur:
- Vorspann (1 Absatz): Kontext-Setting — worum geht es im Interview, warum ist es jetzt aktuell.
- Q&A-Block: jede Frage als eigener Absatz (kursiv oder mit „Frage:" prefix), jede Antwort als 1–4 Sätze des Interviewten. Mindestens 6 Q&A-Paare, gerne mehr.
- Schluss (1 Absatz): Abschluss-Frage und -Antwort, oft auf Persönliches oder Ausblick.

Quellen: 1 (das ursprüngliche Interview). Bei mehreren Quellen für dieselbe Person — komplementäre Zitate aus anderen Interviews zur Anreicherung möglich, aber als solche kennzeichnen.

Ton: dialogisch. Fragen kurz und scharf, Antworten in O-Ton-Treue.

Was zu vermeiden:
- Zusammenfassen statt Wiedergeben — Antworten sollen wörtlich oder nahe wörtlich sein, mit Quelle ‚wie [Name] gegenüber [Quellmedium] sagte'.
- Eigene Wertungen zwischen den Q&As („eine erstaunliche Antwort").
- Antworten kürzen, sodass Sinn verzerrt wird.
"""


OPINION = """TYP: opinion — Meinungsartikel / Kolumne.

Aufbau:
- Title: 40–80 Zeichen, oft These oder Frage („Warum die Schuldenbremse fallen muss").
- Lead: 2 Sätze. Klare These des Autors. Signalisiert: dies ist Meinung, nicht Bericht.
- Body: 2500–4000 Zeichen. Sieben bis zwölf Absätze.

Struktur:
- These (1–2 Absätze): Position klar formulieren.
- Argumentation (4–6 Absätze): jeder Absatz ein Argument, mit Beleg (Zahlen, Zitate, historische Beispiele).
- Gegenargumente und ihre Widerlegung (1–2 Absätze): die wichtigste Gegenposition aufgreifen und entkräften.
- Forderung / Schluss (1 Absatz): konkrete Folgerung, was getan werden sollte.

Quellen: 1 ist OK (es ist Meinungsartikel des Autors). Verweise auf Studien / Daten / andere Stimmen sind willkommen.

Ton:
- Pointiert, mit klarer Stimme. Persönliche Pronomen erlaubt („ich glaube, dass …").
- Eingriffsmöglichkeit erkennen lassen — Kolumnen vom Tisch des Autors.
- Abgrenzung zu Hetze: Kritik an Positionen, nicht an Personen.

Hinweis: opinion-Artikel werden ZUSÄTZLICH zur thematischen Rubrik (Politik / Wirtschaft / etc.) auch in die Rubrik „Meinung" eingeordnet — das ist eine Frage der Kategorisierung in WordPress, nicht des Rewriter-Promtps.

Was zu vermeiden:
- Persönliche Angriffe, beleidigende Sprache.
- Faktenfremde Behauptungen — auch Meinungen brauchen Beleg.
"""


EXPLAINER = """TYP: explainer — sachlich-erklärender Artikel zu komplexem Thema.

Aufbau:
- Title: 50–100 Zeichen, oft Frage-Form („Was bedeutet die EU-Richtlinie X?").
- Lead: 2–3 Sätze. Was ist das Phänomen / die Regelung / der Begriff, den der Artikel erklärt. Warum ist es jetzt relevant.
- Body: 5000+ Zeichen. Acht bis fünfzehn Absätze, gegliedert in benannte Sektionen mit H2-Zwischenüberschriften.

Struktur:
- Sektion 1 „Worum geht es?": Definition / Beschreibung in 2–3 Absätzen, einfache Sprache.
- Sektion 2 „Warum jetzt?": aktueller Anlass, was ist passiert, was steht zur Entscheidung.
- Sektion 3 „Wie funktioniert das?": Mechanik / Prozess / Hintergrund-Logik. Konkrete Beispiele.
- Sektion 4 „Wer ist betroffen?": konkrete Akteure, Zielgruppen, Zahlen.
- Sektion 5 „Was sind die Streitpunkte?": Pro / Contra mit Quellen.
- Sektion 6 „Was kommt als nächstes?": absehbare nächste Schritte (nur faktenbasiert).

Quellen: mindestens 2, gerne 3–5. Mehrere Perspektiven sind für eine seriöse Erklärung nötig.

Ton:
- Sachlich, lehrend, ohne Färbung. Wie ein guter Lehrer.
- Komplexe Begriffe werden bei Erstnennung erklärt.
- Beispiele aus dem Alltag der Zielgruppe (deutsche und ukrainische Diaspora).

Was zu vermeiden:
- Eigene Bewertung („zu Recht", „verständlicherweise") — das ist analysis oder opinion.
- Übermäßige Vereinfachung, die Genauigkeit kostet.
"""


LIVE_BLOG = """TYP: live_blog — fortlaufende Berichterstattung zu einem Ereignis.

Aufbau:
- Title: 40–80 Zeichen, mit Live-Marker („Live-Blog: Bundestagsdebatte zur Rente").
- Lead: 2 Sätze. Was ist das Ereignis, wann läuft es, wer berichtet (das Quellmedium).
- Body: 800+ Zeichen pro Update. Im Live-Blog-Format als chronologische Updates.

Struktur:
- Jedes Update als eigener Absatz mit Zeitstempel: „14:32 — Kanzler Merz erklärt …".
- Zeitstempel werden aus dem Original-Liveblog übernommen, nicht erfunden.
- Mindestens 4–6 Updates pro veröffentlichter Version.
- Updates in absteigender Reihenfolge: neueste oben.

Quellen: 1 (das Quell-Liveblog) — Live-Blog ist immer single-source. Bei mehreren parallelen Liveblogs (z.B. zu derselben Pressekonferenz) — können später zu einem konsolidierten Live-Blog zusammengeführt werden, aber nicht im Erst-Rewrite.

Ton: faktisch, knapp pro Update. Wie eine Schreibmaschine im Saal.

Was zu vermeiden:
- Spekulation in Updates — nur was passiert ist.
- Zusammenfassende Wertungen am Ende — der Live-Blog endet wenn das Ereignis endet.
- Updates erfinden / aus Kontext herleiten.
"""


TYPE_MODULES = {
    "breaking_alert": BREAKING_ALERT,
    "news_brief":     NEWS_BRIEF,
    "news_article":   NEWS_ARTICLE,
    "extended_news":  EXTENDED_NEWS,
    "analysis":       ANALYSIS,
    "feature":        FEATURE,
    "sport_result":   SPORT_RESULT,
    "obituary":       OBITUARY,
    "interview":      INTERVIEW,
    "opinion":        OPINION,
    "explainer":      EXPLAINER,
    "live_blog":      LIVE_BLOG,
}


def type_module(kind: str) -> str:
    """Return the type-specific module for `kind`, or fall back to news_article."""
    return TYPE_MODULES.get(kind, TYPE_MODULES["news_article"])
