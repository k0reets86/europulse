<?php

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function europulse_ensure_demo_media(array $image): int {
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
			'post_content' => $image['caption'],
		]);
		update_post_meta($attachment_id, '_wp_attachment_image_alt', $image['alt']);
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
	update_post_meta($attachment_id, '_wp_attachment_image_alt', $image['alt']);
	update_post_meta($attachment_id, 'europulse_source_url', $image['source_url']);

	return (int) $attachment_id;
}

function europulse_upsert_demo_post(array $post, int $attachment_id): void {
	$existing = get_posts([
		'post_type' => 'post',
		'post_status' => 'any',
		'name' => $post['post_name'],
		'posts_per_page' => 1,
	]);

	$postarr = [
		'post_title' => $post['post_title'],
		'post_name' => $post['post_name'],
		'post_status' => 'publish',
		'post_type' => 'post',
		'post_date' => $post['post_date'],
		'post_date_gmt' => get_gmt_from_date($post['post_date']),
		'post_excerpt' => $post['post_excerpt'],
		'post_content' => $post['post_content'],
		'post_author' => 1,
		'tags_input' => $post['tags'],
	];

	if (! empty($existing[0])) {
		$postarr['ID'] = (int) $existing[0]->ID;
		$post_id = wp_update_post($postarr, true);
	} else {
		$post_id = wp_insert_post($postarr, true);
	}

	if (is_wp_error($post_id)) {
		throw new RuntimeException($post_id->get_error_message());
	}

	wp_set_post_categories((int) $post_id, $post['categories']);
	set_post_thumbnail((int) $post_id, $attachment_id);
	update_post_meta((int) $post_id, 'europulse_sources', [
		[
			'label' => $post['source_label'],
			'url' => $post['source_url'],
		],
	]);
	update_post_meta((int) $post_id, 'europulse_popular_score', $post['popular_score']);

	wp_update_post([
		'ID' => (int) $post_id,
		'comment_status' => 'closed',
		'ping_status' => 'closed',
	]);
}

$images = [
	'marienplatz' => [
		'url' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/M%C3%BCnchen_Marienplatz.jpg',
		'title' => 'München Marienplatz',
		'alt' => 'Marienplatz in München',
		'caption' => 'Foto: Thomas Wolf / Wikimedia Commons, "München Marienplatz", CC BY-SA 3.0',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:M%C3%BCnchen_Marienplatz.jpg',
	],
	'marienplatz_rathaus' => [
		'url' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/Rathaus_and_Marienplatz_from_Peterskirche_-_August_2006.jpg',
		'title' => 'Rathaus and Marienplatz from Peterskirche',
		'alt' => 'Rathaus und Marienplatz in München',
		'caption' => 'Foto: Softeis / Wikimedia Commons, "Rathaus and Marienplatz from Peterskirche - August 2006", CC BY-SA 3.0',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Rathaus_and_Marienplatz_from_Peterskirche_-_August_2006.jpg',
	],
	'bavarian_forest' => [
		'url' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/Bayerischer_wald1.jpg',
		'title' => 'Bayerischer Wald',
		'alt' => 'Landschaft im Bayerischen Wald',
		'caption' => 'Foto: High Contrast / Wikimedia Commons, "Bayerischer wald1", CC BY 3.0',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Bayerischer_wald1.jpg',
	],
	'theater_des_westens' => [
		'url' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/Berlin-Charlottenburg_Theater_des_Westens_05-2014.jpg',
		'title' => 'Theater des Westens',
		'alt' => 'Theater des Westens in Berlin',
		'caption' => 'Foto: Fridolin freudenfett / Wikimedia Commons, "Berlin-Charlottenburg Theater des Westens 05-2014", CC BY-SA 4.0',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Berlin-Charlottenburg_Theater_des_Westens_05-2014.jpg',
	],
	'community_office' => [
		'url' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/B%C3%BCro_der_Workcamp-Organisation_IBG_e.V._in_Stuttgart.jpg',
		'title' => 'Büro der Workcamp-Organisation IBG in Stuttgart',
		'alt' => 'Büro eines Vereins in Stuttgart',
		'caption' => 'Foto: MOs810 / Wikimedia Commons, "Büro der Workcamp-Organisation IBG e.V. in Stuttgart", CC BY-SA 4.0',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:B%C3%BCro_der_Workcamp-Organisation_IBG_e.V._in_Stuttgart.jpg',
	],
];

$attachments = [];
foreach ($images as $key => $image) {
	$attachments[$key] = europulse_ensure_demo_media($image);
}

$posts = [
	[
		'post_title' => 'Veranstaltungen in München werden wieder kleiner, planbarer und nützlicher',
		'post_name' => 'community-veranstaltungen-muenchen-kalender',
		'post_date' => '2026-03-09 19:10:00',
		'post_excerpt' => 'Ein gemeinsamer Veranstaltungskalender soll Termine klarer bündeln und die Community-Kommunikation entlasten.',
		'post_content' => '<p>Mehrere kleinere Initiativen in München arbeiten derzeit an einem gemeinsamen Kalender für Informationsabende, Diskussionsrunden und offene Community-Formate. Ziel ist nicht ein möglichst lauter Veranstaltungsstrom, sondern ein verlässlicher Überblick mit gut planbaren Formaten.</p>
<p>Für eine deutsch-ukrainische Community ist das praktisch relevanter als lose Ankündigungen in vielen einzelnen Kanälen. Wer Hilfe, Austausch oder Kontakte sucht, braucht zuerst klare Orientierung und saubere Terminführung.</p>
<h2>Warum der Schritt sinnvoll ist</h2>
<p>Nach Angaben aus dem Umfeld mehrerer Organisatorinnen sollen Veranstaltungen künftig früher angekündigt, thematisch besser beschrieben und bei Bedarf auch mehrsprachig zusammengefasst werden. Das senkt Friktionen für neue Besucherinnen und Besucher.</p>
<p>Gleichzeitig entsteht damit ein Format, das sich redaktionell gut begleiten lässt: Termine, Einordnung und Rückblick bleiben getrennt, statt in einem undurchsichtigen Mix aus Werbung und Bericht zu enden.</p>
<h2>Worauf EuroPulse achten würde</h2>
<p>Entscheidend ist, ob Veranstaltungen klar zwischen Service, Kultur und Community-Austausch unterscheiden. Nur dann entsteht eine Struktur, die später auch für andere Regionen in Deutschland skalieren kann.</p>',
		'categories' => [46, 48],
		'tags' => ['Community', 'Veranstaltungen', 'München'],
		'source_label' => $images['marienplatz']['title'],
		'source_url' => $images['marienplatz']['source_url'],
		'popular_score' => 154,
		'image' => 'marienplatz',
	],
	[
		'post_title' => 'Vereine und Projekte testen gemeinsame Ansprechpartner statt paralleler Strukturen',
		'post_name' => 'community-vereine-projekte-ansprechpartner',
		'post_date' => '2026-03-09 16:45:00',
		'post_excerpt' => 'Einige deutsch-ukrainische Vereine wollen Erstkontakte bündeln, damit Beratung und Projektarbeit übersichtlicher werden.',
		'post_content' => '<p>Mehrere kleinere Vereine prüfen derzeit, ob sie Anfragen zu Beratung, Engagement und Projektideen zunächst über gemeinsame Kontaktpunkte bündeln. Der Gedanke dahinter ist schlicht: weniger Reibung, weniger Doppelwege, klarere Zuständigkeiten.</p>
<p>Gerade bei begrenzten Kapazitäten können solche Strukturen wertvoller sein als noch ein neues Einzelprojekt. Für Nutzerinnen und Nutzer zählt in der Regel zuerst, ob der Einstieg verständlich und die Antwort schnell genug ist.</p>
<h2>Was sich dadurch ändern könnte</h2>
<p>Wenn die Bündelung gelingt, würden Vereine ihre Facharbeit nicht verlieren. Sie würden aber Anfragen früher filtern und passender verteilen. Das stärkt die Verlässlichkeit im ersten Kontakt und macht Angebote nach außen glaubwürdiger.</p>
<p>Für EuroPulse ist das ein typisches Community-Thema: organisatorisch, alltagsnah und relevant für Integration, ohne es künstlich zu politisieren.</p>
<h2>Nächster Schritt</h2>
<p>Beobachtet wird nun, ob aus dem Testlauf belastbare Partnerschaften entstehen oder ob die beteiligten Gruppen bei ihren bisherigen Einzelwegen bleiben.</p>',
		'categories' => [46, 52],
		'tags' => ['Community', 'Vereine', 'Projekte'],
		'source_label' => $images['community_office']['title'],
		'source_url' => $images['community_office']['source_url'],
		'popular_score' => 149,
		'image' => 'community_office',
	],
	[
		'post_title' => 'Treffen und Networking verlagern sich in kleinere Abende mit klarer Einladung',
		'post_name' => 'community-treffen-networking-kleiner-rahmen',
		'post_date' => '2026-03-09 13:20:00',
		'post_excerpt' => 'Statt großer Sammelformate setzen Organisatorinnen zunehmend auf kleinere Treffen mit klarer Zielgruppe und nachvollziehbarem Zweck.',
		'post_content' => '<p>In mehreren Städten wird bei Networking-Formaten derzeit stärker auf kleinere Runden gesetzt. Das soll vermeiden, dass Treffen zwar sichtbar, aber inhaltlich unklar bleiben und am Ende weder Beratung noch Kooperation wirklich fördern.</p>
<p>Ein präziseres Format kann für neue Teilnehmende sogar hilfreicher sein: Wer eine Einladung liest, soll sofort erkennen können, ob es um beruflichen Austausch, Vereinsarbeit oder Community-Kontakte geht.</p>
<h2>Weniger Größe, mehr Orientierung</h2>
<p>Die Veranstalter begründen den Wechsel mit begrenzten Ressourcen und einem nüchternen Blick auf Wirkung. Kleine, gut moderierte Treffen führen oft schneller zu Anschlusskontakten als große, lose Abende ohne klare Struktur.</p>
<p>Für einen jungen Newsroom wie EuroPulse ist das zugleich ein nützliches redaktionelles Prinzip: weniger Rauschen, mehr Kontext und ein nachvollziehbarer Nutzen für Leserinnen und Leser.</p>',
		'categories' => [46, 54],
		'tags' => ['Community', 'Networking', 'Treffen'],
		'source_label' => $images['marienplatz_rathaus']['title'],
		'source_url' => $images['marienplatz_rathaus']['source_url'],
		'popular_score' => 145,
		'image' => 'marienplatz_rathaus',
	],
	[
		'post_title' => 'Kulturhäuser setzen wieder stärker auf klare Programme statt Event-Druck',
		'post_name' => 'kultur-programme-klare-kuratierung',
		'post_date' => '2026-03-08 16:10:00',
		'post_excerpt' => 'Im Kulturbereich wächst die Nachfrage nach kleineren, gut kuratierten Formaten mit klarer Sprache und nachvollziehbarem Profil.',
		'post_content' => '<p>Im deutschen Kulturbereich lässt sich derzeit ein leiser, aber deutlicher Trend beobachten: Weg von überladenen Programmen, hin zu kuratierten Reihen mit klarer Sprache, besserer Auffindbarkeit und nachvollziehbaren Formaten.</p>
<p>Für ein Publikum zwischen Deutschland, Europa und der Ukraine ist das besonders relevant. Gute Kulturvermittlung beginnt nicht mit Lautstärke, sondern mit klaren Informationen und einer Einladung, die verständlich bleibt.</p>
<h2>Warum diese Entwicklung trägt</h2>
<p>Kleinere Programme lassen sich besser erklären, redaktionell sauber begleiten und im Kalender präziser einordnen. Das stärkt Vertrauen und hilft auch neuen Zielgruppen, Angebote realistischer einzuschätzen.</p>
<p>Eine solche Kulturberichterstattung passt zur gewünschten Linie von EuroPulse: seriös, modern und ohne boulevardeske Dramatisierung.</p>',
		'categories' => [28],
		'tags' => ['Kultur', 'Programm', 'Deutschland'],
		'source_label' => $images['theater_des_westens']['title'],
		'source_url' => $images['theater_des_westens']['source_url'],
		'popular_score' => 141,
		'image' => 'theater_des_westens',
	],
	[
		'post_title' => 'Bayern diskutiert regionale Erreichbarkeit wieder stärker als Standortfrage',
		'post_name' => 'bayern-regionale-erreichbarkeit-standortfrage',
		'post_date' => '2026-03-08 12:30:00',
		'post_excerpt' => 'Zwischen Ballungsraum und ländlichem Raum rückt in Bayern erneut die Frage in den Vordergrund, wie alltagstaugliche Infrastruktur organisiert wird.',
		'post_content' => '<p>In Bayern gewinnt die Diskussion über regionale Erreichbarkeit erneut an Gewicht. Gemeint ist nicht nur Verkehr im engen Sinn, sondern die praktische Frage, wie Menschen Dienstleistungen, Bildung, Beratung und Arbeit verlässlich erreichen.</p>
<p>Gerade aus Sicht neuer Einwohnerinnen und Einwohner ist das ein sehr konkretes Thema. Gute Infrastruktur entscheidet oft schneller über Teilhabe als große politische Schlagworte.</p>
<h2>Zwischen Stadt und Fläche</h2>
<p>Während Ballungsräume ihre Netze verdichten, bleibt in ländlicheren Räumen die Frage offen, wie Angebote erreichbar bleiben, ohne dass Kosten und Wege ausufern. Kommunalpolitik und Wirtschaft schauen deshalb wieder stärker auf regionale Knotenpunkte.</p>
<p>Für EuroPulse ist das ein klassischer Bayern-Stoff: lokal genug für konkrete Orientierung, aber relevant genug für eine größere Deutschland-Perspektive.</p>',
		'categories' => [14, 12],
		'tags' => ['Bayern', 'Infrastruktur', 'Deutschland'],
		'source_label' => $images['bavarian_forest']['title'],
		'source_url' => $images['bavarian_forest']['source_url'],
		'popular_score' => 138,
		'image' => 'bavarian_forest',
	],
];

foreach ($posts as $post) {
	europulse_upsert_demo_post($post, $attachments[$post['image']]);
}

echo "Editorial demo upgraded.\n";
