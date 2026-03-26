<?php

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$images = [
	'munich_skyline' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/4/48/Munich_skyline.jpg',
		'title' => 'Munich skyline',
		'alt' => 'Münchner Skyline',
		'caption' => 'Foto: Stefan Kühn / Wikimedia Commons, "Munich skyline", CC BY-SA 3.0',
		'source_label' => 'Wikimedia Commons: Munich skyline',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Munich_skyline.jpg',
	],
	'frauenkirche_munich' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/9/9b/Frauenkirche_Munich%2C_March_2018.jpg',
		'title' => 'Frauenkirche Munich, March 2018',
		'alt' => 'Frauenkirche in München',
		'caption' => 'Foto: Martin Falbisoner / Wikimedia Commons, "Frauenkirche Munich, March 2018", CC BY-SA 4.0',
		'source_label' => 'Wikimedia Commons: Frauenkirche Munich, March 2018',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Frauenkirche_Munich,_March_2018.jpg',
	],
	'berlin_gate' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/5/51/Berlin_Brandenburg_Gate_%2828688262781%29.jpg',
		'title' => 'Berlin Brandenburg Gate',
		'alt' => 'Brandenburger Tor in Berlin',
		'caption' => 'Foto: Gary Todd / Wikimedia Commons, "Berlin Brandenburg Gate", CC0',
		'source_label' => 'Wikimedia Commons: Berlin Brandenburg Gate',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Berlin_Brandenburg_Gate_(28688262781).jpg',
	],
	'reichstag' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/2/28/Reichstag_pano.jpg',
		'title' => 'Reichstag pano',
		'alt' => 'Reichstag in Berlin',
		'caption' => 'Foto: Daniel Schwen / Wikimedia Commons, "Reichstag pano", CC BY-SA 4.0',
		'source_label' => 'Wikimedia Commons: Reichstag pano',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Reichstag_pano.jpg',
	],
	'kyiv' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/d/d4/Kyiv_Ukraine.jpg',
		'title' => 'Kyiv Ukraine',
		'alt' => 'Kyjiw Stadtansicht',
		'caption' => 'Foto: Creation3987!GO / Wikimedia Commons, "Kyiv Ukraine", CC0',
		'source_label' => 'Wikimedia Commons: Kyiv Ukraine',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Kyiv_Ukraine.jpg',
	],
	'community_rally' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/4/4d/Saturday%2C_24_February_2024_Ukrainian_Community_Mass_Rally_on_the_2nd_Anniversary_of_Russia%27s_War_Against_Ukraine_%40_Lincoln_Memorial_-_Washington_DC_I_%2853552786579%29.jpg',
		'title' => 'Ukrainian Community Mass Rally 2024',
		'alt' => 'Ukrainische Community bei einer Kundgebung',
		'caption' => 'Foto: Elvert Barnes / Wikimedia Commons, "Ukrainian Community Mass Rally 2024", CC BY-SA 2.0',
		'source_label' => 'Wikimedia Commons: Ukrainian Community Mass Rally 2024',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Saturday,_24_February_2024_Ukrainian_Community_Mass_Rally_on_the_2nd_Anniversary_of_Russia%27s_War_Against_Ukraine_@_Lincoln_Memorial_-_Washington_DC_I_(53552786579).jpg',
	],
	'frankfurt_night' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/e/eb/Frankfurt_Skyline_2022_bei_Nacht.jpg',
		'title' => 'Frankfurt Skyline 2022 bei Nacht',
		'alt' => 'Frankfurter Skyline bei Nacht',
		'caption' => 'Foto: Jörg Braukmann / Wikimedia Commons, "Frankfurt Skyline 2022 bei Nacht", CC BY-SA 4.0',
		'source_label' => 'Wikimedia Commons: Frankfurt Skyline 2022 bei Nacht',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Frankfurt_Skyline_2022_bei_Nacht.jpg',
	],
	'ecb_frankfurt' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/5/51/Seat_of_the_European_Central_Bank_and_Frankfurt_Skyline_at_dawn_20150422_1.jpg',
		'title' => 'Seat of the European Central Bank and Frankfurt Skyline at dawn',
		'alt' => 'EZB und Frankfurter Skyline',
		'caption' => 'Foto: DXR / Wikimedia Commons, "Seat of the European Central Bank and Frankfurt Skyline at dawn", CC BY-SA 4.0',
		'source_label' => 'Wikimedia Commons: ECB and Frankfurt Skyline at dawn',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Seat_of_the_European_Central_Bank_and_Frankfurt_Skyline_at_dawn_20150422_1.jpg',
	],
	'eu_parliament' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/2/2c/European_Parliament_Strasbourg_Hemicycle_-_Diliff.jpg',
		'title' => 'European Parliament Strasbourg Hemicycle',
		'alt' => 'Europäisches Parlament in Straßburg',
		'caption' => 'Foto: David Iliff / Wikimedia Commons, "European Parliament Strasbourg Hemicycle", CC BY-SA 3.0',
		'source_label' => 'Wikimedia Commons: European Parliament Strasbourg Hemicycle',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:European_Parliament_Strasbourg_Hemicycle_-_Diliff.jpg',
	],
	'hamburg_harbor' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/a1/Hamburg-Harbor-by-eschenzweig.jpg',
		'title' => 'Hamburg Harbor',
		'alt' => 'Hamburger Hafen',
		'caption' => 'Foto: Eschenzweig / Wikimedia Commons, "Hamburg Harbor", CC BY-SA 4.0',
		'source_label' => 'Wikimedia Commons: Hamburg Harbor',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Hamburg-Harbor-by-eschenzweig.jpg',
	],
	'olympic_stadium' => [
		'url' => 'https://upload.wikimedia.org/wikipedia/commons/c/ca/Olympic_Stadium_Munich_-_Rows_of_Seats%2C_April_2019_-04.jpg',
		'title' => 'Olympic Stadium Munich - Rows of Seats',
		'alt' => 'Olympiastadion München',
		'caption' => 'Foto: Martin Falbisoner / Wikimedia Commons, "Olympic Stadium Munich - Rows of Seats", CC BY-SA 4.0',
		'source_label' => 'Wikimedia Commons: Olympic Stadium Munich',
		'source_url' => 'https://commons.wikimedia.org/wiki/File:Olympic_Stadium_Munich_-_Rows_of_Seats,_April_2019_-04.jpg',
	],
];

function europulse_media_attachment(array $image): int {
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

$attachments = [];
foreach ($images as $key => $image) {
	$attachments[$key] = europulse_media_attachment($image);
}

$mapping = [
	64 => 'munich_skyline',
	65 => 'frauenkirche_munich',
	66 => 'kyiv',
	67 => 'kyiv',
	68 => 'eu_parliament',
	69 => 'eu_parliament',
	70 => 'reichstag',
	71 => 'reichstag',
	72 => 'frankfurt_night',
	73 => 'ecb_frankfurt',
	74 => 'hamburg_harbor',
	75 => 'berlin_gate',
	76 => 'community_rally',
	77 => 'community_rally',
	78 => 'frauenkirche_munich',
	79 => 'munich_skyline',
	80 => 'olympic_stadium',
	81 => 'olympic_stadium',
];

foreach ($mapping as $post_id => $image_key) {
	if (! isset($attachments[$image_key], $images[$image_key])) {
		continue;
	}

	set_post_thumbnail($post_id, $attachments[$image_key]);

	update_post_meta($post_id, 'europulse_sources', [
		[
			'label' => $images[$image_key]['source_label'],
			'url' => $images[$image_key]['source_url'],
		],
	]);
}

echo "Demo media refreshed.\n";
