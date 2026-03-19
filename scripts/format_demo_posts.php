<?php

$ids = [64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79, 80, 81, 114, 116, 118, 120, 122];

foreach ($ids as $id) {
	$post = get_post($id);

	if (! $post) {
		continue;
	}

	$content = $post->post_content;

	if ($content === '') {
		continue;
	}

	if (strpos($content, 'europulse-direct-quote') === false) {
		$quote = '<p class="europulse-direct-quote"><strong>&#8222;Wichtig ist nicht die Größe des Formats, sondern seine Verlässlichkeit im Alltag&#8220;, heißt es aus dem redaktionellen Umfeld.</strong></p>';
		$content = preg_replace('#</p>#', '</p>' . $quote, $content, 1);
	}

	if (strpos($content, 'europulse-editor-note') === false) {
		$note = '<p class="europulse-editor-note"><em>Hinweis: Dieser Beitrag ist ein redaktionell aufgebautes Testformat für die strukturelle Prüfung von EuroPulse.</em></p>';
		$content .= $note;
	}

	wp_update_post([
		'ID' => $id,
		'post_content' => $content,
	]);
}

echo "formatted posts\n";
