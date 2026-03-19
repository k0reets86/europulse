<?php

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$author_id = 1;

wp_update_user([
	'ID' => $author_id,
	'display_name' => 'EuroPulse Redaktion',
	'nickname' => 'EuroPulse Redaktion',
]);

$images = [
	'munich' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/4/48/Munich_skyline.jpg',
		'title' => 'Munich skyline',
		'caption' => 'Foto: Stefan Kühn / Wikimedia Commons, "Munich skyline", CC BY-SA 3.0',
		'source_label' => 'Wikimedia Commons: Munich skyline',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Munich_skyline.jpg',
	],
	'berlin' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/5/51/Berlin_Brandenburg_Gate_%2828688262781%29.jpg',
		'title' => 'Berlin Brandenburg Gate',
		'caption' => 'Foto: Gary Todd / Wikimedia Commons, "Berlin Brandenburg Gate", CC0',
		'source_label' => 'Wikimedia Commons: Berlin Brandenburg Gate',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Berlin_Brandenburg_Gate_(28688262781).jpg',
	],
	'kyiv' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/d/d4/Kyiv_Ukraine.jpg',
		'title' => 'Kyiv Ukraine',
		'caption' => 'Foto: Creation3987!GO / Wikimedia Commons, "Kyiv Ukraine", CC0',
		'source_label' => 'Wikimedia Commons: Kyiv Ukraine',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Kyiv_Ukraine.jpg',
	],
];

$categories = [];

foreach ([
	'Deutschland',
	'München',
	'Bayern',
	'Ukraine',
	'Europa',
	'Politik',
	'Wirtschaft',
	'Leben in Deutschland',
	'Kultur',
	'Sport',
	'Community',
	'Veranstaltungen',
	'Ukrainische Initiativen',
	'Vereine & Projekte',
	'Treffen & Networking',
] as $name) {
	$term = get_term_by('name', $name, 'category');

	if ($term) {
		$categories[$name] = (int) $term->term_id;
	}
}

function europulse_seed_attachment(array $image): int {
	$existing = get_posts([
		'post_type' => 'attachment',
		'post_status' => 'inherit',
		'posts_per_page' => 1,
		'meta_key' => 'europulse_source_url',
		'meta_value' => $image['source_url'],
		'fields' => 'ids',
	]);

	if (! empty($existing[0])) {
		$attachment_id = (int) $existing[0];
		wp_update_post([
			'ID' => $attachment_id,
			'post_title' => $image['title'],
			'post_excerpt' => $image['caption'],
		]);

		return $attachment_id;
	}

	$attachment_id = media_sideload_image($image['url'], 0, $image['title'], 'id');

	if (is_wp_error($attachment_id)) {
		throw new RuntimeException($attachment_id->get_error_message());
	}

	wp_update_post([
		'ID' => $attachment_id,
		'post_title' => $image['title'],
		'post_excerpt' => $image['caption'],
		'post_content' => $image['caption'],
	]);

	update_post_meta($attachment_id, 'europulse_source_url', $image['source_url']);

	return (int) $attachment_id;
}

$attachment_ids = [];

foreach ($images as $key => $image) {
	$attachment_ids[$key] = europulse_seed_attachment($image);
}

$posts = [
	[
		'slug' => 'europulse-start-muenchen-kulturhaus',
		'title' => 'München plant neues Kulturhaus für ukrainische und deutsche Initiativen',
		'excerpt' => 'Die Stadt prüft ein gemeinsames Modell für Beratung, Veranstaltungen und kleinere Kulturformate mit klarer kommunaler Trägerschaft.',
		'content' => [
			'Im Münchner Rathaus laufen derzeit Gespräche über einen dauerhaft nutzbaren Ort für Community-Veranstaltungen, Beratungsangebote und kleinere Kulturformate. Nach Angaben aus dem Umfeld der Stadtverwaltung steht dabei kein symbolisches Projekt im Vordergrund, sondern eine belastbare Infrastruktur mit klaren Zuständigkeiten.',
			'Der Ansatz ist für EuroPulse relevant, weil er zwei Nutzungsszenarien zusammenführt: öffentlich zugängliche Veranstaltungen und kontinuierliche Community-Arbeit. Gerade für eine deutsch-ukrainische Zielgruppe ist das belastbarer als punktuelle Treffen ohne festen organisatorischen Rahmen.',
			'Nach dem aktuellen Planungsstand würde ein solches Haus Räume für Informationsabende, Sprachformate, kleinere Bühnenprogramme und Projektarbeit bereitstellen. Offen ist noch, welche Träger dauerhaft beteiligt würden und wie die Finanzierung zwischen Stadt, Partnern und Fördermitteln verteilt wird.',
			'Für die nächsten Wochen wird mit einer politischen Vorprüfung gerechnet. Sollte das Projekt weiterverfolgt werden, wäre es ein naheliegender Pilot für die künftige Community-Berichterstattung auf EuroPulse.',
		],
		'categories' => ['Deutschland', 'München'],
		'tags' => ['München', 'Community', 'Integration'],
		'image' => 'munich',
		'date' => '2026-03-12 10:20:00',
		'popular_score' => 98,
	],
	[
		'slug' => 'bayern-kommunen-bildung-ukraine',
		'title' => 'Bayern bündelt kommunale Bildungsangebote für neu angekommene Familien',
		'excerpt' => 'Mehrere Städte in Bayern arbeiten an einer gemeinsamen Übersicht zu Sprachkursen, Beratungen und schulnahen Angeboten.',
		'content' => [
			'Mehrere bayerische Kommunen wollen ihre Informationen zu Sprachkursen, Familienberatung und schulnahen Unterstützungsangeboten in einer einheitlicheren Struktur veröffentlichen. Ziel ist es, dass neue Anlaufstellen schneller auffindbar sind und lokale Initiativen weniger parallel arbeiten müssen.',
			'Im Kern geht es nicht um ein neues Landesprogramm, sondern um eine praktischere Kommunikation. Für Familien, die zwischen Erstinformation, Schule, Terminorganisation und Alltagsfragen pendeln, ist die Bündelung solcher Hinweise oft wichtiger als zusätzliche Einzelprojekte.',
			'Nach Einschätzung kommunaler Akteure bleibt die größte Hürde die Pflege der Daten: Öffnungszeiten, Zuständigkeiten und Kontaktwege ändern sich regelmäßig. Deshalb wird bereits darüber gesprochen, feste Verantwortlichkeiten für Aktualisierung und Qualitätssicherung zu definieren.',
			'Wenn diese Struktur funktioniert, könnte sie später auf weitere Regionen übertragen werden. Für ein Nachrichtenportal ist das ein klassisches Service-Thema mit hoher Relevanz für den Alltag.',
		],
		'categories' => ['Deutschland', 'Bayern'],
		'tags' => ['Bayern', 'Bildung', 'Familien'],
		'image' => 'berlin',
		'date' => '2026-03-12 09:40:00',
		'popular_score' => 90,
	],
	[
		'slug' => 'ukraine-energie-kommunale-resilienz',
		'title' => 'Kommunen in der Ukraine setzen stärker auf lokale Resilienzprojekte',
		'excerpt' => 'Im Fokus stehen kleinere, schnell umsetzbare Maßnahmen für Energie, Beratung und zivile Infrastruktur.',
		'content' => [
			'Mehrere ukrainische Städte richten ihren Fokus derzeit stärker auf lokale Resilienzprojekte mit kurzer Umsetzungszeit. Dazu gehören kleinere Energielösungen, mobile Unterstützungsangebote und pragmatische Infrastrukturmaßnahmen mit direktem Nutzen für den Alltag.',
			'Für viele Kommunen ist das die realistischste Ebene des Handelns: Große Programme brauchen Zeit, während kleinere Eingriffe sofort Wirkung entfalten können. Genau diese Zwischenebene wird in vielen internationalen Berichten oft zu wenig sichtbar.',
			'Gespräche mit lokalen Akteuren zeigen, dass die Projekte vor allem dann tragfähig werden, wenn sie durch zuverlässige Partnerschaften und transparente Zuständigkeiten begleitet werden. Nicht Größe, sondern Verlässlichkeit ist derzeit der entscheidende Faktor.',
			'Für EuroPulse ist das ein Beispiel dafür, wie Ukraine-Berichterstattung zugleich politisch relevant und nah am Alltag bleiben kann.',
		],
		'categories' => ['Ukraine'],
		'tags' => ['Ukraine', 'Kommunen', 'Resilienz'],
		'image' => 'kyiv',
		'date' => '2026-03-12 08:55:00',
		'popular_score' => 92,
	],
	[
		'slug' => 'ukraine-zivilgesellschaft-projekte',
		'title' => 'Neue zivilgesellschaftliche Projekte in Kyjiw setzen auf kleine, überprüfbare Ziele',
		'excerpt' => 'Organisationen berichten von einem Trend zu klar begrenzten Vorhaben mit messbarem lokalen Nutzen.',
		'content' => [
			'In Kyjiw entstehen derzeit mehrere zivilgesellschaftliche Projekte, die bewusst klein beginnen und mit klar messbaren Zielen arbeiten. Dieser Ansatz soll helfen, Ressourcen präziser einzusetzen und Vertrauen bei Partnern und Unterstützern zu sichern.',
			'Typisch für diese neue Phase ist eine nüchternere Sprache: weniger große Versprechen, mehr überprüfbare Ergebnisse. Das betrifft sowohl Bildungsformate als auch psychosoziale Unterstützung und community-nahe Angebote.',
			'Gerade für internationale Partner ist diese Arbeitsweise wichtig, weil sie die Bewertung erleichtert. Statt abstrakter Wirkungserzählungen lassen sich konkrete Formate, Teilnehmerzahlen und Folgeeffekte besser dokumentieren.',
			'Für eine deutschsprachige Leserschaft entsteht daraus ein differenzierteres Bild der Ukraine: nicht nur Krise und Diplomatie, sondern auch institutionelles Lernen und Projektpraxis.',
		],
		'categories' => ['Ukraine'],
		'tags' => ['Kyjiw', 'Zivilgesellschaft', 'Projekte'],
		'image' => 'kyiv',
		'date' => '2026-03-11 18:20:00',
		'popular_score' => 87,
	],
	[
		'slug' => 'europa-mobilitaet-regional',
		'title' => 'Europa diskutiert Mobilität wieder stärker aus regionaler Perspektive',
		'excerpt' => 'Statt nur über Fernverkehr zu sprechen, rücken Verbindungen zwischen Regionen und Mittelstädten stärker in den Mittelpunkt.',
		'content' => [
			'In mehreren europäischen Debatten verschiebt sich der Fokus derzeit von symbolträchtigen Großprojekten hin zu regionaler Mobilität. Besonders relevant sind Verbindungen zwischen Mittelstädten, Grenzregionen und wirtschaftlich eng verflochtenen Räumen.',
			'Diese Diskussion hat unmittelbare Bedeutung für Menschen, die zwischen Deutschland, der Ukraine und anderen europäischen Ländern unterwegs sind. Nicht jede Mobilitätsfrage ist eine Hauptstadtfrage.',
			'Analysten verweisen darauf, dass regionale Netze gesellschaftliche Teilhabe, Bildung und wirtschaftliche Chancen oft stärker beeinflussen als einzelne Prestigeprojekte. Damit wird das Thema politisch und sozial zugleich.',
			'Für EuroPulse eignet sich dieser Blick, weil er europäische Politik in konkrete Alltagslogik übersetzt.',
		],
		'categories' => ['Europa'],
		'tags' => ['Europa', 'Mobilität', 'Regionen'],
		'image' => 'berlin',
		'date' => '2026-03-11 16:00:00',
		'popular_score' => 75,
	],
	[
		'slug' => 'europa-staedtekooperation',
		'title' => 'Städtekooperationen in Europa werden wieder strategischer gedacht',
		'excerpt' => 'Partnerschaften sollen weniger symbolisch und stärker an Infrastruktur, Bildung und Kultur gekoppelt werden.',
		'content' => [
			'Europäische Städtepartnerschaften werden vielerorts neu bewertet. Der Trend geht weg von rein repräsentativen Formaten und hin zu Kooperationen mit klaren Arbeitsfeldern wie Bildung, Verwaltung, Kultur und kommunale Resilienz.',
			'Das ist auch für EuroPulse relevant, weil solche Strukturen reale Anknüpfungspunkte für deutsch-ukrainische Projekte schaffen können. Je klarer die Themenachsen, desto belastbarer werden die Kooperationen.',
			'Kommunale Netzwerke berichten, dass gerade kleinere und mittlere Städte dabei pragmatischer vorgehen als große Metropolen. Häufig zählt nicht Sichtbarkeit, sondern die Frage, ob Ergebnisse innerhalb eines Jahres sichtbar werden.',
			'Für ein seriöses News-Portal bietet dieser Bereich genug Stoff für kontinuierliche, nicht-tabloide Berichterstattung.',
		],
		'categories' => ['Europa'],
		'tags' => ['Europa', 'Städte', 'Kooperation'],
		'image' => 'munich',
		'date' => '2026-03-11 14:15:00',
		'popular_score' => 70,
	],
	[
		'slug' => 'politik-bund-kommunen-koordinierung',
		'title' => 'Bund und Kommunen suchen nach klareren Zuständigkeiten in Integrationsfragen',
		'excerpt' => 'Praktische Koordinierung rückt stärker in den Mittelpunkt als neue politische Schlagworte.',
		'content' => [
			'In der politischen Debatte in Deutschland rückt derzeit die Frage nach klareren Zuständigkeiten zwischen Bund, Ländern und Kommunen stärker in den Vordergrund. Besonders sichtbar wird das in Bereichen, in denen rechtliche Vorgaben und praktische Umsetzung auseinanderlaufen.',
			'Für Integrations- und Community-Themen ist das relevant, weil Unsicherheit über Zuständigkeiten häufig zu langsamer Hilfe und unklarer Kommunikation führt. Politische Effizienz ist hier oft eine Organisationsfrage.',
			'Mehrere kommunale Vertreter plädieren dafür, Service-Informationen, Beratungslogik und Förderketten stärker zu standardisieren. Das würde nicht alle Probleme lösen, aber die Reibungsverluste im Alltag spürbar senken.',
			'Die Debatte wirkt technisch, ist aber politisch zentral. Wer Zuständigkeiten ordnet, prägt auch, wie handlungsfähig staatliche Strukturen wahrgenommen werden.',
		],
		'categories' => ['Politik'],
		'tags' => ['Politik', 'Deutschland', 'Kommunen'],
		'image' => 'berlin',
		'date' => '2026-03-11 12:10:00',
		'popular_score' => 82,
	],
	[
		'slug' => 'politik-europa-ukraine-hilfe',
		'title' => 'Europäische Hilfe für die Ukraine wird stärker an Umsetzbarkeit gemessen',
		'excerpt' => 'Im Vordergrund stehen Belastbarkeit, Transparenz und regionale Anschlussfähigkeit.',
		'content' => [
			'Die politische Diskussion über Unterstützung für die Ukraine verschiebt sich in vielen europäischen Hauptstädten in Richtung Umsetzbarkeit. Neben strategischen Zielen werden operative Fragen sichtbarer: Wer setzt was um, mit welchen Partnern und in welchem Zeitrahmen?',
			'Diese nüchterne Perspektive verändert auch die öffentliche Kommunikation. Projekte müssen nachvollziehbarer erklärt werden, wenn sie dauerhaft Akzeptanz finden sollen.',
			'Gleichzeitig wächst der Druck, Hilfen so zu gestalten, dass sie regional andocken können. Pauschale Formeln verlieren an Überzeugungskraft, wenn vor Ort andere Prioritäten spürbar werden.',
			'Gerade hier braucht es Medien, die Komplexität erklären, ohne in dramatische Vereinfachung zu kippen. Das ist der redaktionelle Raum, in dem EuroPulse funktionieren soll.',
		],
		'categories' => ['Politik', 'Europa', 'Ukraine'],
		'tags' => ['Europa', 'Ukraine', 'Politik'],
		'image' => 'kyiv',
		'date' => '2026-03-11 10:35:00',
		'popular_score' => 84,
	],
	[
		'slug' => 'wirtschaft-arbeitsmarkt-bayern',
		'title' => 'Bayerische Unternehmen suchen pragmatische Wege für internationale Fachkräfte',
		'excerpt' => 'Im Mittelpunkt stehen nicht Kampagnen, sondern Anerkennung, Sprache und verlässliche Prozesse.',
		'content' => [
			'Unternehmen in Bayern sprechen derzeit weniger über große Fachkräfte-Kampagnen und stärker über konkrete Engpässe im Alltag. Dazu gehören Anerkennungsverfahren, Sprachpraxis im Beruf und eine bessere Begleitung in den ersten Monaten.',
			'Der wirtschaftliche Nutzen solcher Maßnahmen ist bekannt, ihre Umsetzung bleibt aber oft zu kleinteilig organisiert. Genau hier entscheiden sich Geschwindigkeit und Glaubwürdigkeit.',
			'Für Menschen, die neu nach Deutschland kommen, zählt vor allem, ob Prozesse nachvollziehbar und zeitlich planbar sind. Wirtschaftspolitik wird an dieser Stelle sehr konkret.',
			'Diese Perspektive macht das Thema anschlussfähig für ein breiteres Publikum: nicht als abstrakte Debatte über Arbeitsmarkt, sondern als Frage funktionierender Übergänge.',
		],
		'categories' => ['Wirtschaft', 'Bayern'],
		'tags' => ['Wirtschaft', 'Arbeitsmarkt', 'Bayern'],
		'image' => 'munich',
		'date' => '2026-03-10 18:00:00',
		'popular_score' => 72,
	],
	[
		'slug' => 'wirtschaft-europa-kmu',
		'title' => 'Kleine Unternehmen in Europa fordern verlässlichere Förderlogik',
		'excerpt' => 'Vor allem Planbarkeit und geringere Bürokratie gelten als entscheidend für Investitionen.',
		'content' => [
			'Kleine und mittlere Unternehmen in Europa drängen auf planbarere Förderinstrumente und weniger kurzfristige Richtungswechsel. Nicht die Zahl der Programme ist das Hauptproblem, sondern ihre Uneinheitlichkeit.',
			'Gerade in grenzüberschreitenden Kontexten erhöhen unterschiedliche Fristen, Dokumentationspflichten und Zuständigkeiten die Kosten spürbar. Das bremst Projekte, bevor sie überhaupt beginnen.',
			'Ökonomisch betrachtet geht es damit nicht nur um Beihilfen, sondern um institutionelle Klarheit. Wer investieren soll, braucht verlässliche Regeln.',
			'Für EuroPulse ist das ein gutes Beispiel dafür, wie Wirtschaftsberichterstattung sachlich und lesbar zugleich bleiben kann.',
		],
		'categories' => ['Wirtschaft', 'Europa'],
		'tags' => ['Europa', 'KMU', 'Förderung'],
		'image' => 'berlin',
		'date' => '2026-03-10 15:20:00',
		'popular_score' => 68,
	],
	[
		'slug' => 'leben-in-deutschland-alltag-beratung',
		'title' => 'Leben in Deutschland: Warum gute Erstinformationen oft wichtiger sind als neue Programme',
		'excerpt' => 'Viele Alltagsprobleme entstehen nicht durch fehlende Angebote, sondern durch unübersichtliche Informationen.',
		'content' => [
			'Wer neu in Deutschland ankommt, stößt häufig nicht zuerst auf fehlende Angebote, sondern auf unklare Wege zu bereits existierenden Hilfen. Gerade bei Wohnen, Schule, Terminen und Dokumenten entscheidet die Qualität der Erstinformation über den weiteren Verlauf.',
			'Mehrere Beratungsstellen berichten, dass dieselben Fragen immer wieder auftauchen, obwohl die Informationen grundsätzlich vorhanden sind. Das Problem ist oft die Struktur, nicht der Inhalt.',
			'Deshalb gewinnen kompakte, verständliche Service-Formate an Bedeutung. Sie ersetzen keine Beratung, aber sie senken Einstiegshürden und schaffen Orientierung.',
			'Für EuroPulse ist dieses Themenfeld zentral, weil es Alltag, Verwaltung und Community-Nutzen verbindet.',
		],
		'categories' => ['Leben in Deutschland'],
		'tags' => ['Service', 'Deutschland', 'Beratung'],
		'image' => 'berlin',
		'date' => '2026-03-10 12:40:00',
		'popular_score' => 88,
	],
	[
		'slug' => 'leben-in-deutschland-sprache-alltag',
		'title' => 'Sprachpraxis im Alltag bleibt für viele wichtiger als formale Kursstunden',
		'excerpt' => 'Niedrigschwellige Formate und informelle Praxisräume spielen eine größere Rolle als oft angenommen.',
		'content' => [
			'Sprachkurse bleiben wichtig, doch für viele Menschen entscheidet sich Fortschritt im Alltag: beim Arzttermin, im Elternkontakt, in Behörden oder im Beruf. Deshalb rücken informelle Praxisräume stärker in den Vordergrund.',
			'Bibliotheken, Nachbarschaftsorte und kleinere Initiativen bieten dafür oft bessere Bedingungen als starre Formate. Entscheidend ist, dass Schwellen niedrig und Wege nachvollziehbar sind.',
			'Für Medien ist das ein dankbares Thema, weil es Service- und Gesellschaftsberichterstattung verbindet, ohne belehrend zu wirken.',
			'Gute redaktionelle Aufbereitung sollte hier konkrete Hinweise, Stimmen aus der Praxis und eine klare Sprache zusammenführen.',
		],
		'categories' => ['Leben in Deutschland'],
		'tags' => ['Sprache', 'Alltag', 'Integration'],
		'image' => 'munich',
		'date' => '2026-03-10 10:10:00',
		'popular_score' => 77,
	],
	[
		'slug' => 'community-veranstaltungen-fruehjahr',
		'title' => 'Community plant kleinere Frühjahrsformate statt einer großen Sammelveranstaltung',
		'excerpt' => 'Kompakte Treffen mit klarem Zweck wirken nachhaltiger als überladene Einmal-Events.',
		'content' => [
			'Mehrere Akteure aus der Community bevorzugen für das Frühjahr kleinere Formate mit klarer Funktion: Informationsabende, thematische Treffen und gezielte Begegnungsräume. Der Trend geht weg von großen Sammelveranstaltungen mit unscharfem Profil.',
			'Das ist nicht nur organisatorisch sinnvoll, sondern auch redaktionell besser anschlussfähig. Kleine Formate lassen sich verständlicher ankündigen, dokumentieren und evaluieren.',
			'Für Nutzerinnen und Nutzer zählt vor allem, ob sie wissen, warum sich ein Besuch lohnt. Ein klares Format schafft Vertrauen und verbessert Reichweite auf natürliche Weise.',
			'EuroPulse kann diesen Bereich als eigene Community-Zone führen, ohne ihn mit regionaler oder politischer Berichterstattung zu vermischen.',
		],
		'categories' => ['Community', 'Veranstaltungen'],
		'tags' => ['Community', 'Veranstaltungen', 'Frühjahr'],
		'image' => 'munich',
		'date' => '2026-03-09 17:00:00',
		'popular_score' => 85,
	],
	[
		'slug' => 'community-initiativen-netzwerk',
		'title' => 'Ukrainische Initiativen setzen stärker auf Kooperation statt Parallelstrukturen',
		'excerpt' => 'Gemeinsame Kalender, geteilte Räume und klarere Rollen sollen die Sichtbarkeit erhöhen.',
		'content' => [
			'Mehrere ukrainische Initiativen im deutschsprachigen Raum sprechen sich für engere Koordination aus. Im Mittelpunkt stehen gemeinsame Kalender, geteilte Infrastruktur und eine klarere Aufgabenverteilung.',
			'Der Schritt wirkt unspektakulär, ist aber strategisch wichtig. Sichtbarkeit wächst nicht nur durch mehr Aktivität, sondern auch durch weniger Reibungsverlust.',
			'Für Veranstalter und Projekte bedeutet das vor allem: weniger Überschneidungen, mehr Planbarkeit und bessere Kommunikation nach außen.',
			'Genau deshalb braucht EuroPulse einen eigenen Community-Bereich mit sauberer Struktur und klaren Unterrubriken.',
		],
		'categories' => ['Community', 'Ukrainische Initiativen'],
		'tags' => ['Initiativen', 'Netzwerk', 'Community'],
		'image' => 'kyiv',
		'date' => '2026-03-09 14:30:00',
		'popular_score' => 81,
	],
	[
		'slug' => 'kultur-muenchen-programm',
		'title' => 'Kultur in München: Kleine Programme mit klarer Kuratierung gewinnen an Profil',
		'excerpt' => 'Weniger Event-Überladung, mehr verlässliche Reihen und nachvollziehbare Themenachsen.',
		'content' => [
			'Im Kulturbereich wächst das Interesse an kleineren, kuratierten Formaten mit klar erkennbarer Handschrift. Statt Event-Fülle setzen Veranstalter stärker auf Reihen, die sich über mehrere Termine entwickeln.',
			'Für das Publikum ist das oft attraktiver als ein überladener Kalender. Wiedererkennbarkeit schafft Vertrauen und macht kulturelle Angebote planbarer.',
			'Gerade im deutsch-ukrainischen Kontext können solche Formate Brücken bauen, ohne sich auf symbolische Gesten zu beschränken.',
			'Das Thema eignet sich deshalb gut für eine Kultur-Rubrik, die nicht auf bloße Ankündigungen reduziert wird.',
		],
		'categories' => ['Kultur', 'München'],
		'tags' => ['Kultur', 'München', 'Programm'],
		'image' => 'munich',
		'date' => '2026-03-09 11:15:00',
		'popular_score' => 66,
	],
	[
		'slug' => 'kultur-europa-austausch',
		'title' => 'Europäischer Kulturaustausch setzt wieder stärker auf kleinere Partnerschaften',
		'excerpt' => 'Nicht Größe, sondern Kontinuität und programmatische Klarheit werden zum Qualitätsmerkmal.',
		'content' => [
			'Im europäischen Kulturbereich gewinnen kleinere Partnerschaften mit klar definierten Programmlinien an Bedeutung. Der Fokus liegt weniger auf kurzfristiger Aufmerksamkeit und stärker auf belastbaren Austauschformaten.',
			'Diese Entwicklung ist auch für lokale Redaktionen interessant, weil sie transnationale Themen greifbarer macht. Gute Kulturberichterstattung zeigt nicht nur Premieren, sondern Strukturen.',
			'Partnerschaften funktionieren besonders dort, wo Themen, Zielgruppen und Zeithorizonte klar formuliert sind. Das wirkt im ersten Moment strenger, ist langfristig aber erfolgreicher.',
			'Für EuroPulse entsteht daraus eine Kultur-Rubrik mit glaubwürdiger, ruhiger Tonlage statt Event-Rhetorik.',
		],
		'categories' => ['Kultur', 'Europa'],
		'tags' => ['Kultur', 'Europa', 'Austausch'],
		'image' => 'berlin',
		'date' => '2026-03-08 18:40:00',
		'popular_score' => 61,
	],
	[
		'slug' => 'sport-muenchen-breitensport',
		'title' => 'Breitensport in München setzt stärker auf offene Formate für neue Zielgruppen',
		'excerpt' => 'Vereine und Initiativen testen niedrigschwellige Angebote mit klaren Zeitfenstern und einfacher Anmeldung.',
		'content' => [
			'Im Münchner Breitensport entstehen vermehrt offene Formate, die neue Zielgruppen ohne lange Vorlaufzeiten ansprechen sollen. Entscheidend sind einfache Anmeldung, klare Zeiten und sichtbare Ansprechpartner.',
			'Für Menschen, die neu in einer Stadt sind, ist genau das oft der Unterschied zwischen Interesse und tatsächlicher Teilnahme. Sport wird dadurch auch zu einer praktischen Integrationsschnittstelle.',
			'Mehrere Vereine beobachten, dass kleine, gut organisierte Einstiegsformate nachhaltiger wirken als groß angekündigte Sonderaktionen.',
			'Das macht das Thema für EuroPulse interessant: nicht als Event-Show, sondern als Teil städtischen Alltags.',
		],
		'categories' => ['Sport', 'München'],
		'tags' => ['Sport', 'München', 'Vereine'],
		'image' => 'munich',
		'date' => '2026-03-08 14:05:00',
		'popular_score' => 58,
	],
	[
		'slug' => 'sport-europa-nachwuchs',
		'title' => 'Europäische Nachwuchsprogramme im Sport werden wieder kleinteiliger geplant',
		'excerpt' => 'Planbarkeit, Betreuung und regionale Partnerschaften rücken stärker in den Vordergrund.',
		'content' => [
			'Im europäischen Nachwuchssport werden Programme zunehmend kleiner und präziser geplant. Statt möglichst großer Reichweite zählen Betreuung, regionale Anbindung und verlässliche Strukturen.',
			'Diese Entwicklung ist auch deshalb relevant, weil sie zeigt, wie Organisation Qualität sichern will, ohne in symbolische Größe zu flüchten.',
			'Für das Publikum sind solche Themen anschlussfähig, wenn sie verständlich erklärt und nicht künstlich dramatisiert werden.',
			'EuroPulse kann Sport damit als sachliche, strukturierte Rubrik anlegen, die über bloße Resultate hinausgeht.',
		],
		'categories' => ['Sport', 'Europa'],
		'tags' => ['Sport', 'Europa', 'Nachwuchs'],
		'image' => 'berlin',
		'date' => '2026-03-08 10:30:00',
		'popular_score' => 55,
	],
];

foreach ($posts as $index => $post_data) {
	$existing = get_page_by_path($post_data['slug'], OBJECT, 'post');

	$postarr = [
		'ID' => $existing ? $existing->ID : 0,
		'post_type' => 'post',
		'post_status' => 'publish',
		'post_author' => $author_id,
		'post_name' => $post_data['slug'],
		'post_title' => $post_data['title'],
		'post_excerpt' => $post_data['excerpt'],
		'post_date' => $post_data['date'],
		'post_content' => implode("\n\n", array_map(static function ($paragraph) {
			return '<p>' . wp_kses_post($paragraph) . '</p>';
		}, $post_data['content'])),
	];

	$post_id = wp_insert_post($postarr, true);

	if (is_wp_error($post_id)) {
		throw new RuntimeException($post_id->get_error_message());
	}

	$post_id = (int) $post_id;

	$category_ids = [];

	foreach ($post_data['categories'] as $category_name) {
		if (! empty($categories[$category_name])) {
			$category_ids[] = $categories[$category_name];
		}
	}

	wp_set_post_terms($post_id, $category_ids, 'category', false);
	wp_set_post_terms($post_id, $post_data['tags'], 'post_tag', false);
	set_post_thumbnail($post_id, $attachment_ids[$post_data['image']]);
	update_post_meta($post_id, 'europulse_demo_post', 1);
	update_post_meta($post_id, 'europulse_popular_score', (int) $post_data['popular_score']);
	update_post_meta($post_id, 'europulse_sources', [
		[
			'label' => $images[$post_data['image']]['source_label'],
			'url' => $images[$post_data['image']]['source_url'],
		],
	]);

	if (function_exists('pll_set_post_language')) {
		pll_set_post_language($post_id, 'de');
	}
}

$sticky = get_page_by_path('europulse-start-muenchen-kulturhaus', OBJECT, 'post');
update_option('sticky_posts', $sticky ? [(int) $sticky->ID] : []);

$widget_blocks = get_option('widget_block', []);
$widget_blocks[4]['content'] = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Meistgelesen</h3><!-- /wp:heading --><!-- wp:shortcode -->[europulse_most_read posts="5"]<!-- /wp:shortcode --></div><!-- /wp:group -->';
update_option('widget_block', $widget_blocks);

$theme_mods = get_option('theme_mods_blocksy', []);
$theme_mods['single_blog_post_has_related_posts'] = 'yes';
$theme_mods['single_blog_post_related_label'] = 'Weiterlesen';
$theme_mods['single_blog_post_related_posts_count'] = 3;
$theme_mods['has_sticky_header'] = [
	'desktop' => true,
	'tablet' => true,
	'mobile' => true,
	'behaviour' => 'middle',
];
update_option('theme_mods_blocksy', $theme_mods);

echo "EuroPulse demo content seeded.\n";
