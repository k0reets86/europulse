<?php

declare(strict_types=1);

$root = '/var/www/europulse/public';
require_once $root . '/wp-load.php';

$targets = [];
$home = home_url('/');
$targets[] = $home;

$posts = get_posts([
	'post_type' => 'post',
	'post_status' => 'publish',
	'numberposts' => 6,
	'orderby' => 'date',
	'order' => 'DESC',
]);

foreach ($posts as $post) {
	$targets[] = get_permalink($post);
}

$targets = array_values(array_unique(array_filter($targets)));
$report = [];

foreach ($targets as $url) {
	$html = @file_get_contents($url);
	if (! is_string($html) || $html === '') {
		$report[] = ['url' => $url, 'ok' => false, 'errors' => ['empty response']];
		continue;
	}

	$errors = [];
	$warnings = [];
	$dom = new DOMDocument();
	@$dom->loadHTML($html);
	$xpath = new DOMXPath($dom);

	if ($xpath->query('//title')->length === 0) {
		$errors[] = 'missing title';
	}
	if ($xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description" and string-length(@content) > 0]')->length === 0) {
		$errors[] = 'missing meta description';
	}
	if ($xpath->query('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical" and string-length(@href) > 0]')->length === 0) {
		$errors[] = 'missing canonical';
	}
	if ($xpath->query('//link[@hreflang]')->length === 0) {
		$warnings[] = 'missing hreflang';
	}
	if (! preg_match('/application\/ld\+json/is', $html)) {
		$warnings[] = 'missing structured data';
	}
	if (! preg_match('/NewsArticle|Article|BreadcrumbList/is', $html)) {
		$warnings[] = 'structured data not obviously article/breadcrumb';
	}
	if ($xpath->query('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image" and string-length(@content) > 0]')->length === 0) {
		$warnings[] = 'missing og:image';
	}
	if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/is', $html)) {
		$errors[] = 'page is noindex';
	}

	$report[] = [
		'url' => $url,
		'ok' => $errors === [],
		'errors' => $errors,
		'warnings' => $warnings,
	];
}

echo wp_json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
