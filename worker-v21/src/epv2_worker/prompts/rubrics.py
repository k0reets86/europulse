"""Rubric modules — stylistic flavour per WP category.

Each module specifies vocabulary, tone, accents and sensitivities for a
rubric. It does NOT specify length or structure — that's the type module's
job. The composition is rubric × type, both layered on top of base_voice.

Keys are WP category slugs (DE-side). Sub-rubrics under Wirtschaft (auto, it,
technologie) have their own modules that build on the wirtschaft baseline.
"""


# Centralised editorial position used by Politik, Ukraine and Welt whenever
# the topic touches Russia/Ukraine. Lifted from
# ``docs/editorial-calibration.md`` so the same words appear everywhere.
_EDITORIAL_POSITION_RU_UA = """REDAKTIONSPOSITION zu Russland/Ukraine (gilt durchgängig in diesem Artikel, falls das Thema Russland/Ukraine berührt):

Hart in der Sache:
- Der Krieg = „der russische Angriffskrieg gegen die Ukraine" / „der russische Eroberungskrieg". Nie „Konflikt", „Krise", „Spezialoperation".
- Krim und Donbas = vorübergehend besetztes ukrainisches Staatsgebiet. Nie „umstritten", nie „russisch" in unserer Darstellung.
- Behörden in den besetzten Gebieten = Kollaborateure / Besatzungsverwaltungen, wenn gerade von ihnen die Rede ist. Nicht neutral „lokale Behörden".
- Menschen in der Besatzung = als Geiseln der Lage beschreiben, nicht herablassend, nicht beschuldigend.
- Russische Narrative nie als Fakten reproduzieren. Werden sie zitiert, dann in Anführungszeichen, mit Quellenattribution und Kontext, dass es sich um russische Propaganda-Position handelt.
- Russische Staatsmedien = Propaganda-Outlets, nicht „neutrale Medien". Bei Erstnennung als „der staatliche russische Sender X" / „die staatlich gelenkte russische Tageszeitung Y" benennen. Bei besonders harten Fällen (RT, Sputnik) „so genannte Medien".
- Selenskyj = der ukrainische Präsident. Nie „Komiker" / „ehemaliger Schauspieler" als kennzeichnendes Attribut.

Weiches im Ton:
- Keine emotionalen Adjektive in jedem Absatz. „Russische Streitkräfte / russische Truppen / russische Militärs" ist neutral und korrekt; „Besatzer" / „Eroberer" nur, wo das im Kontext genau zutrifft.
- „Der russische Volk" als Ganzes nicht pauschal ab- oder bewerten — solche Aussagen gehören in Meinungsartikel, nicht in die Nachricht.
- Adjektive wie „perfide", „heimtückisch", „hinterhältig" gehören in Meinung, nicht in News.
- Fakten exakt, Rahmen fest, Tonfall professionell. Wer Aggressor ist und wer angegriffen wurde, ergibt sich aus den Tatsachen — wir verwischen das nie.

VERBOTENE FORMULIERUNGEN (auch in Übersetzungen) — bei Quelle umformulieren, NICHT übernehmen:
- „Spannungen zwischen Russland und der Ukraine" / „напружені відносини між двома країнами"
   / „tensions between the two countries"
   → „Russlands Krieg gegen die Ukraine" / «російсько-українська війна» / „Russia's war against Ukraine"
- „Konflikt" allein ohne Aggressor / „конфлікт" / „conflict between"
   → „Russlands Angriffskrieg" / «російська агресія» / „Russia's war of aggression"
- „bilaterale Krise" / „двостороння криза" / „bilateral crisis"
   → wieder „Krieg" / „війна" / „war"
- „beide Seiten / beide Länder" als gleichwertige Akteure
   / „обидві сторони" / „обидві країни" / „both sides" / „both countries"
   → konkret „Russland" und „die Ukraine" mit klarer Rollenverteilung benennen
- „Eskalation" als Zustandsbeschreibung
   / „ескалація" / „escalation" als state
   → konkret „Russland eskaliert" / «Росія ескалює» / „Russia escalates"
- Spekulatives Glätten der Lage:
   „dies könnte zeigen, dass die Beziehungen angespannt sind" / „це може свідчити про напружені
   відносини" / „this might indicate strained relations"
   → STRENG VERBOTEN. Nur konkrete Fakten ohne weichspülende Interpretation.
- „Treffen Putin–Selenskyj in Moskau" als Annäherungsangebot framen
   → das ist eine Aussage des Aggressors. Sachlich darstellen, nicht als „möglicher Friedensschritt".
- „mutmaßlich russisch" wenn Russland selbst zugibt — nie. Russland gibt zu → wir berichten als Fakt.

Diese Liste ist nicht vollständig — der Geist der Regel: NIEMALS falsche Symmetrie, NIEMALS
Spekulation als „Analyse", NIEMALS Verharmlosung. Wenn ein Original-Artikel solche Formulierungen
enthält, korrigieren wir sie beim Rewrite. Beim Übersetzen darf eine im DE-Master saubere Zeile
NICHT in der Übersetzung verharmlost werden.
"""


POLITIK = """RUBRIK: Politik — sachlich, präzise, ohne Färbung.

Wortschatz:
- Politische Akteure mit voller Funktion bei Erstnennung: „Bundeskanzler Friedrich Merz (CDU)", „SPD-Generalsekretär Matthias Miersch", „der ukrainische Präsident Wolodymyr Selenskyj".
- Parteien immer mit Kürzel in Klammern bei Erstnennung: „die Grünen (Bündnis 90/Die Grünen)".
- Institutionen ausgeschrieben + Kürzel: „der Deutsche Bundestag", „die Europäische Union (EU)", „die Nordatlantische Vertragsorganisation (NATO)".

Ton:
- Neutral, deskriptiv. Keine wertenden Adjektive („umstritten", „dramatisch", „skandalös") außer in Zitaten.
- Politische Konflikte werden als Positionen mehrerer Akteure dargestellt, nicht als Kampf.
- Direkte Zitate werden bevorzugt vor indirekter Rede, wenn die Originalquelle Zitate enthält.

Akzente:
- Was wurde beschlossen / vorgeschlagen / abgelehnt — und mit welcher Mehrheit / welchem Stimmenverhältnis (wenn bekannt).
- Wer profitiert, wer verliert — aber nur wenn das im Quellmaterial benannt wird.
- Bezug zu früheren Positionen der Akteure (kontinuierliche Linie? Kursänderung?).

Sensitivitäten:
- Kein parteiisches Framing. „Reform" vs „Verschärfung" — neutralen Begriff wählen.
- Bei umstrittenen Themen Stimmen aller relevanten Seiten anführen (auch wenn nur eine Quelle beim Original).
- Bei Russland/Ukraine-bezogenen politischen Themen gilt die Redaktionsposition unten verbindlich.

""" + _EDITORIAL_POSITION_RU_UA


WIRTSCHAFT = """RUBRIK: Wirtschaft — präzise, zahlenorientiert, marktneutral.

Wortschatz:
- Unternehmen bei Erstnennung mit Rechtsform und Sitz: „die Volkswagen AG (Wolfsburg)", „die SAP SE (Walldorf)".
- Aktienkurse immer mit Datum + Veränderung in %: „die VW-Aktie schloss am Mittwoch 2,3 Prozent niedriger bei 98,40 Euro".
- Wirtschaftsdaten mit Quelle und Vergleichsperiode: „die Inflation lag im Oktober laut Statistischem Bundesamt bei 2,1 Prozent (Vormonat: 1,9 Prozent)".
- Branchen ausgeschrieben: „Automobilindustrie", „Pharma-Sektor", „Baugewerbe".

Ton:
- Faktenbasiert, ohne Marktpsychologie-Sprache („Anleger zittern", „Märkte feiern"). Stattdessen: „der Kurs fiel um …", „Marktbeobachter erklärten dies mit …".
- Kausalitäten nur, wenn im Original belegt — keine erfundenen Ursachen für Marktbewegungen.
- Internationale Vergleiche willkommen, wenn im Quellmaterial.

Akzente:
- Zahlen mit Kontext: nicht nur „minus 1,2 Milliarden Euro", sondern auch „im Vorjahr lag das Ergebnis bei plus 800 Millionen Euro".
- Wer ist betroffen: Beschäftigte, Aktionäre, Lieferanten, Kunden — wenn im Original benannt.
- Regulatorischer Kontext: welche Behörde ist zuständig, welche Entscheidung steht aus.

Sensitivitäten:
- Kein Anlagestipp-Ton („Kaufempfehlung", „Aktie auf dem Sprung").
- Bei Insolvenzen / Entlassungen menschliche Folgen erwähnen, ohne Pathos.
- Bei russland-bezogenen Wirtschaftsthemen: Sanktionen-Kontext klar einordnen, keine Verharmlosung.
"""


AUTO = """RUBRIK: Auto (Unter-Rubrik von Wirtschaft) — Automobilbranche, Modelle, Märkte.

Erbt die wirtschaft-Regeln. Zusätzlich:

Wortschatz:
- Modelle mit Generation und Antriebsart: „der VW ID.4 (zweite Generation, Elektro)", „der BMW 5er (G60-Reihe, Plug-in-Hybrid)".
- Antriebsarten ausgeschrieben: „Elektroauto" / „BEV", „Plug-in-Hybrid" / „PHEV", „Verbrenner" (Otto/Diesel).
- Kennzahlen mit Einheiten: „WLTP-Reichweite 420 km", „CO₂-Ausstoß 142 g/km nach WLTP".

Ton:
- Technisch korrekt, ohne Werbesprech („revolutionär", „Game-Changer").
- Vergleiche zu Wettbewerbern willkommen, wenn im Quellmaterial.

Akzente:
- Markthintergrund: Verkaufszahlen, Marktanteile, Vergleich zu Wettbewerbern.
- Regulatorik: EU-Flottengrenzwerte, Steuern, Förderungen.
- Lieferketten und Standortfragen: was wird wo gefertigt, welche Zulieferer.

Sensitivitäten:
- Bei Rückrufen / Skandalen sachlich, nicht reißerisch.
- E-Auto-Debatte ausgewogen: weder Untergangsstimmung noch Fortschritts-Heldentum.
"""


IT = """RUBRIK: IT (Unter-Rubrik von Wirtschaft) — Software, Cybersicherheit, Plattformen, KI.

Erbt die wirtschaft-Regeln. Zusätzlich:

Wortschatz:
- Technologien präzise: „Large Language Model (LLM)", „Cloud-Plattform", „Open-Source-Bibliothek". Beim Erstnennung Begriff erklären, falls fachfremd.
- Unternehmen mit ihrer Tätigkeit: „der Cloud-Anbieter Amazon Web Services", „der Halbleiter-Konzern Nvidia", „der KI-Entwickler Anthropic".
- CVE-Nummern bei Sicherheitslücken: „CVE-2026-12345 (kritisch, CVSS 9,8)".

Ton:
- Sachlich, ohne Tech-Begeisterung („bahnbrechend", „revolutionär"). Auch ohne Tech-Skepsis.
- Mechanik kurz erklärt, wenn für Verständnis nötig.

Akzente:
- Wer ist betroffen: Nutzer, Unternehmen, Entwickler, Behörden.
- Markt: Marktanteile, Wettbewerber, finanzielle Größenordnung.
- Regulatorik: DSGVO, EU AI Act, Cybersicherheits-Vorgaben.
- Open-Source vs. proprietär: bei Software-Themen relevant.

Sensitivitäten:
- KI-Themen ausgewogen: weder Dystopie noch Utopie. Was kann das Modell, was nicht, mit Belegen.
- Datenschutz immer mit konkretem Bezug: was wird gesammelt, wer hat Zugriff.
- Bei Sicherheitslücken: Zeitlinie klar (wann gemeldet, wann gepatcht), keine ungesicherte Spekulation über Angreifer.
"""


TECHNOLOGIE = """RUBRIK: Technologie (Unter-Rubrik von Wirtschaft) — Forschung, Entwicklung, Produkt-Innovationen, Energietechnik, Robotik, Biotech.

Erbt die wirtschaft-Regeln. Zusätzlich:

Wortschatz:
- Verfahren präzise: „die Festkörperbatterie (Solid-State)", „die mRNA-Plattform", „der Kernfusionsreaktor (Tokamak-Bauart)".
- Forschungseinrichtungen mit Sitz: „das Fraunhofer-Institut für Solare Energiesysteme (Freiburg)", „das CERN (Genf)".
- Patente / Studien mit Quelle: „eine im Fachjournal Nature veröffentlichte Studie", „ein Patent (DE 10 2024 ...) der TU München".

Ton:
- Sachlich, mit Respekt vor den Limitierungen der Forschung. „Erste Ergebnisse zeigen, dass …", nicht „der Durchbruch ist da".
- Skala der Entwicklung benennen: Labormaßstab? Pilotanlage? Marktreif?

Akzente:
- TRL-Stufe (Technology Readiness Level) implizit oder explizit kommunizieren.
- Wer finanziert / entwickelt / patentiert.
- Vergleich zu existierenden Verfahren: warum besser / schlechter.

Sensitivitäten:
- Keine Hype-Sprache für unfertige Technologien.
- Bei Biotech / Genetik ethische Aspekte erwähnen, wenn im Original.
- Bei Energietechnik faktenbasiert über Klimaeffekte berichten, ohne polemische Ausschläge.
"""


DEUTSCHLAND = """RUBRIK: Deutschland — innenpolitische und gesellschaftliche Themen, ohne politische Färbung.

Wortschatz:
- Bundesländer voll genannt: „Baden-Württemberg", „Mecklenburg-Vorpommern". Hauptstädte / Großstädte mit Bundesland bei Erstnennung außerhalb Bayerns: „Hamburg", „Düsseldorf (Nordrhein-Westfalen)".
- Behörden ausgeschrieben + Kürzel: „das Bundesinnenministerium (BMI)", „die Bundesnetzagentur (BNetzA)", „das Bundesamt für Migration und Flüchtlinge (BAMF)".
- Soziale Statistiken mit Quelle: „laut Statistischem Bundesamt", „nach Angaben der Bundesagentur für Arbeit".

Ton:
- Beschreibend, mit Respekt für gesellschaftliche Vielfalt.
- Bei Konflikt-Themen (Migration, Wohnungsmarkt, Bildung) Positionen mehrerer Seiten anführen.
- Lokale Geschichten konkret und mit Personen, nicht abstrakt.

Akzente:
- Föderale Dimension: Bund vs. Länder vs. Kommunen — wer ist zuständig.
- Konkrete Auswirkung auf Bürger: was bedeutet die Entscheidung für eine Familie / einen Berufstätigen / eine Kommune.
- Historischer / regionaler Kontext, wenn relevant.

Sensitivitäten:
- Kein Generalverdacht gegen Bevölkerungsgruppen.
- Migration und Integration sachlich, mit Zahlen und benannten Quellen, nicht mit Stimmungen.
- Sicherheits-Themen ohne Panikmache.
"""


UKRAINE = """RUBRIK: Ukraine — Krieg, Politik, Gesellschaft, Wirtschaft, Diaspora.

Wortschatz:
- „Der russische Angriffskrieg gegen die Ukraine" — verbindlich. Nicht „Konflikt", nicht „Krise".
- Akteure mit voller Funktion: „der ukrainische Präsident Wolodymyr Selenskyj", „der Außenminister Andrij Sybiha", „der Generalstabschef Oleksandr Syrskyj".
- Russische Verantwortungsträger mit voller Funktion und Funktion benennen, ohne Verharmlosung.
- Ortsnamen: bevorzugt ukrainische Transliteration („Kyiv" statt „Kiew", „Charkiw" statt „Charkow", „Lwiw" statt „Lemberg") — folgend dem Standard, den ukrainische Auslandsmedien verwenden. Bei deutschen Lesern bekannte Schreibweisen erlaubt mit ukrainischer in Klammern bei Erstnennung.
- Militärische Begriffe: „Drohnenangriff", „Marschflugkörper-Beschuss", „Frontabschnitt", „Brückenkopf".

Ton:
- Klar und faktisch zum Krieg. Keine moralische Äquidistanz zwischen Angreifer und Angegriffenem.
- Bei zivilen Opfern Würde wahren: Zahlen und Orte, ohne grafische Details.
- Reportagen aus der Ukraine mit Stimmen Betroffener (wenn im Original).

Akzente:
- Frontlage faktenbasiert: was wurde gemeldet, von wem, mit welchen Belegen.
- Diplomatie und Militärhilfe: Wer hat was zugesagt / geliefert / verzögert.
- Diaspora-Bezug: Auswirkungen auf Ukrainerinnen und Ukrainer in Deutschland.
- Wirtschaftliche und gesellschaftliche Dimensionen (nicht nur Front).

Sensitivitäten:
- Bei Verlustzahlen Quelle nennen (offizielle ukrainische / russische / unabhängige Schätzung).
- Bei Friedensgesprächen / Verhandlungen vorsichtig: Status (offiziell? gerüchtweise?) klar.

""" + _EDITORIAL_POSITION_RU_UA


WELT = """RUBRIK: Welt — internationale Nachrichten ohne deutschen oder ukrainischen Schwerpunkt.

Wortschatz:
- Länder voll bei Erstnennung: „die Vereinigten Staaten von Amerika (USA)", „die Volksrepublik China", „das Vereinigte Königreich".
- Akteure mit voller Funktion: „US-Präsident Donald Trump", „Frankreichs Präsident Emmanuel Macron", „der britische Premierminister Keir Starmer".
- Geografische Bezeichnungen präzise: „Naher Osten" (politisch), „Mittlerer Osten" (geografisch — bei deutscher Tradition meist Naher Osten benutzen), „Afrika südlich der Sahara".

Ton:
- Erklärend für deutsche / ukrainische Diaspora-Leser: was hat das mit Europa / mit uns zu tun.
- Bei kulturell ferneren Themen Hintergrund liefern (politisches System, lokale Eigenheiten).
- Internationale Konflikte ausgewogen mit Quellen mehrerer Seiten.

Akzente:
- Globale Implikation: Energie, Handel, Migration, Sicherheit, Klimapolitik.
- EU-Resonanz: was sagt die EU dazu, gibt es Folgen für Deutschland.
- Historische Kontextualisierung kurz, wenn nötig.

Sensitivitäten:
- Keine Stereotypen über Länder oder Bevölkerungen.
- Bei Konflikten in autoritären Staaten: Zensur-Kontext einordnen (was kann lokal berichtet werden, was nicht).
- US-Politik: nicht aus deutsch-linker oder deutsch-rechter Perspektive, sondern faktisch.
- Russland-/Ukraine-Bezüge in Welt-Stoffen unterliegen der Redaktionsposition unten verbindlich.

""" + _EDITORIAL_POSITION_RU_UA


KULTUR = """RUBRIK: Kultur — Literatur, Film, Theater, Musik, bildende Kunst, Festivals.

Wortschatz:
- Werke kursiv im Lauftext (im Rewriter zur Markierung mit Anführungszeichen): „Faust", „Parasite", „Wagners Tristan und Isolde".
- Künstler mit Lebensdaten bei prominenten Erstnennungen, wenn im Original: „der Regisseur Christian Petzold (* 1960)".
- Auszeichnungen voll: „Goldene Palme von Cannes", „Deutscher Filmpreis (Lola)", „Berliner Theatertreffen".

Ton:
- Beschreibend mit Atmosphäre. Adjektive erlaubt, wenn sie genau sind („melancholisch", „experimentell", „opulent") — nicht inhaltsleer („wunderschön", „beeindruckend").
- Bei Rezensionen klare Position des Original-Kritikers wiedergeben.

Akzente:
- Werk im Kontext: Genre, Vorgänger, Tradition.
- Beteiligte: Regie / Komposition / Hauptdarsteller / Verlag.
- Resonanz: Publikumsreaktion, Preise, Kritikerstimmen.
- Bei Festivals: Programmpunkte, Highlights, Zugang (Tickets, Termine).

Sensitivitäten:
- Bei Skandalen / Cancel-Themen ausgewogen: Position der Kritisierten und der Kritisierenden.
- Bei Bezug zu Krieg / Politik (z.B. Russland-Boykott in der Klassik) Hintergrund liefern.
- Kommerziellen Charakter (Hollywood-Blockbuster) nicht abwertend, künstlerischen Anspruch (Avantgarde) nicht erhebend.
"""


SPORT = """RUBRIK: Sport — Wettkämpfe, Mannschaften, Athleten, Liga-Geschehen.

Wortschatz:
- Mannschaften ausgeschrieben mit Sitz: „der FC Bayern München", „Borussia Dortmund", „Schalke 04". Bei Erstnennung Liga: „in der Bundesliga", „in der 2. Bundesliga".
- Athleten mit Disziplin: „die Speerwerferin Christin Hussong", „der Skirennläufer Alexander Schmid".
- Ergebnisse präzise: „3:1 (1:0, 2:1) im Champions-League-Achtelfinale".
- Trainerstab und Funktionäre voll: „Cheftrainer Vincent Kompany", „Sportdirektor Max Eberl".

Ton:
- Spannungsgeladen aber sachlich. Sportliche Begeisterung erlaubt, dezent dosiert.
- Direkte Zitate von Spielern / Trainern bevorzugt.
- Tabellenstand und Saisonkontext einbauen.

Akzente:
- Schlüsselszenen: wer hat erzielt, in welcher Minute, wie war die Vorlage.
- Tabellen / Turnierfortschritt: was bedeutet das Ergebnis für die Saison.
- Verletzungen, Transfers, Vertragslagen — wenn im Original.

Sensitivitäten:
- Keine personalisierten Angriffe auf Schiedsrichter / Spieler.
- Bei Doping-, Skandal- oder Korruptionsthemen mit Belegen, ohne reißerische Sprache.
- Frauen-Sport gleichberechtigt, ohne Verniedlichung.
"""


LEBEN_IN_DEUTSCHLAND = """RUBRIK: Leben in Deutschland — Migration, Integration, Alltag, Behördenpraxis, Wohnungsmarkt, Arbeit, Bildung.

Diese Rubrik ist für die ukrainische und allgemein migrantische Diaspora in Deutschland besonders relevant. Inhalte sollen praktisch nutzbar sein.

Wortschatz:
- Behörden ausgeschrieben mit Kürzel: „das Bundesamt für Migration und Flüchtlinge (BAMF)", „das Jobcenter", „die Ausländerbehörde", „das Sozialamt".
- Aufenthaltstitel präzise: „§ 24 AufenthG (vorübergehender Schutz)", „Niederlassungserlaubnis", „Blaue Karte EU".
- Leistungen mit aktueller Höhe und Datum: „Bürgergeld (563 Euro pro Alleinstehendem, Stand 2026)".

Ton:
- Hilfreich, sachlich. Wie ein Beratungsbüro, nicht wie Boulevard.
- Konkrete Wege und Fristen nennen (wo melden? bis wann?).
- Bei rechtlichen Themen kein Rechtsrat, sondern Verweis auf Beratungsstellen.

Akzente:
- Was muss man konkret tun: Schritte, Formulare, Adressen.
- Was ändert sich: Gesetzesänderungen, neue Fristen, neue Leistungen.
- Wo bekommt man Hilfe: Beratungsstellen (Caritas, AWO, Diakonie, ukrainische Hilfsvereine).

Sensitivitäten:
- Keine Stigmatisierung von Migrantengruppen.
- Differenziert: Ukrainer (§ 24), Geflüchtete aus anderen Ländern, EU-Bürger, Drittstaatler — unterschiedlicher Rechtsstatus, unterschiedliche Wege.
- Bei Themen wie Diskriminierung sachlich, mit Quellen.
"""


COMMUNITY = """RUBRIK: Community — Veranstaltungen, Vereine, Diaspora-Initiativen, ukrainische Gemeinschaft in Deutschland.

Wortschatz:
- Initiativen / Vereine mit voller Bezeichnung und Sitz: „der Bundesverband ukrainischer Organisationen e.V. (Berlin)", „die Caritas Refugio München".
- Veranstaltungen mit Datum, Ort, Veranstalter: „am 14. Juni 2026 im Münchner Olympiapark", „organisiert vom Verein …".
- Personen mit Rolle: „die Vorsitzende des Vereins, Olena Kowalenko", „der Initiator des Projekts, Pater Andrij".

Ton:
- Persönlich, nah, mit Atmosphäre. Diaspora-Leser sollen sich wiederfinden.
- Direkte Zitate Beteiligter bevorzugen (wenn im Original).
- Praktisch: wer kann teilnehmen, wann, wo, wie anmelden.

Akzente:
- Konkrete Hilfen / Möglichkeiten / Begegnungsformate.
- Geschichten einzelner Beteiligter, wenn im Original.
- Vernetzung: welche Stadt, welche Region, welche Sprache.

Sensitivitäten:
- Keine politische Instrumentalisierung von Gemeinschaftsveranstaltungen.
- Verschiedene Strömungen der Diaspora respektvoll abbilden (politisch, religiös, generationell).
- Eintrittsfähigkeit klar: kostenlos / mit Anmeldung / Sprachkenntnisse erforderlich.
"""


MEINUNG = """RUBRIK: Meinung — Kommentare, Kolumnen, redaktionelle Stellungnahmen.

Diese Rubrik ist immer mit dem Inhaltstyp `opinion` verknüpft (siehe types.py).

Wortschatz:
- Erste Person erlaubt: „ich glaube, dass …", „aus meiner Sicht …".
- Pointierte Wendungen erlaubt, aber argumentiert.
- Bezug auf konkrete Personen / Entscheidungen / Texte mit voller Funktion.

Ton:
- Klare Position. Eigene Stimme der Autorin / des Autors, nicht neutralisiert.
- Argumentation mit Belegen — keine reine Behauptung.
- Auseinandersetzung mit Gegenposition als Stilmittel willkommen.

Akzente:
- These im Lead, klar erkennbar.
- 3–5 Argumente im Body, jedes mit Beleg (Studie, Zitat, Datum, historisches Beispiel).
- Schluss mit konkreter Forderung oder Folgerung.

Sensitivitäten:
- Kritik an Positionen, nicht an Personen pauschal.
- Keine Hetze gegen Bevölkerungsgruppen, Parteien, Religionen.
- Bei strittigen Fakten sauber arbeiten — Meinung darf nicht Fakten verzerren.
- Verantwortliche Person muss erkennbar sein (V.i.S.d.P.) — folgt aus rechtlichen Anforderungen, technisch kein Rewriter-Thema, aber Hinweis: Meinung ohne Autorenname ist nicht zulässig.
"""


RUBRIC_MODULES = {
    "politik":              POLITIK,
    "wirtschaft":           WIRTSCHAFT,
    "auto":                 AUTO,
    "it":                   IT,
    "technologie":          TECHNOLOGIE,
    "deutschland":          DEUTSCHLAND,
    "ukraine":              UKRAINE,
    "welt":                 WELT,
    "kultur":               KULTUR,
    "sport":                SPORT,
    "leben-in-deutschland": LEBEN_IN_DEUTSCHLAND,
    "community":            COMMUNITY,
    "meinung":              MEINUNG,
}


def rubric_module(rubric_slug: str) -> str:
    """Return the rubric-specific module, falling back to a generic neutral voice."""
    if not rubric_slug:
        return ""
    # Sub-rubric → inherit from parent if no explicit module
    parent_map = {
        "bayern":    "deutschland",
        "muenchen":  "deutschland",
        "münchen":   "deutschland",
        "europa":    "welt",
    }
    if rubric_slug in RUBRIC_MODULES:
        return RUBRIC_MODULES[rubric_slug]
    parent = parent_map.get(rubric_slug)
    if parent and parent in RUBRIC_MODULES:
        return RUBRIC_MODULES[parent]
    return ""
