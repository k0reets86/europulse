<?php
/**
 * Plugin Name: EuroPulse Legacy Redirects
 * Description: 301-редиректы для URL старого украиноязычного сайта (до 2026) на /uk/. Старые слаги — украинский транслит в корне домена; Google до сих пор их запрашивает.
 */

if (! defined('ABSPATH')) {
	exit;
}

add_action('template_redirect', static function (): void {
	if (! is_404()) {
		return;
	}
	$req_path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

	// 1) Карта удалённых дубль-постов (2026-06-16): уже проиндексированный
	// Google URL удалённой статьи 301 → на выжившую версию той же истории.
	$gone = get_option('epv2_gone_redirects', []);
	if (is_array($gone) && $gone !== []) {
		$key = rtrim($req_path, '/');
		if (isset($gone[$key])) {
			wp_redirect(home_url($gone[$key]), 301);
			exit;
		}
	}

	$path = trim($req_path, '/');
	// Только корневые слаги-статьи: один сегмент, длинный, без точек (не файлы).
	if ($path === '' || str_contains($path, '/') || str_contains($path, '.') || strlen($path) < 20) {
		return;
	}
	// Маркеры украинской транслитерации (cz/shh/chch/yj/... в немецких и
	// английских слагах не встречаются). Покрывает слаги старого сайта.
	if (preg_match('/(cz|shh|chch|yj|ukrayin|iyi|oyi|yiv|zhyt|skyh|nnya)/', $path) !== 1) {
		return;
	}
	$target = function_exists('pll_home_url') ? pll_home_url('uk') : home_url('/uk/');
	wp_redirect($target, 301);
	exit;
});
