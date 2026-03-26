<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Google_News {
	public static function resolve_url(string $url): string {
		if (strpos($url, 'news.google.com') === false) {
			return $url;
		}

		$response = wp_remote_get($url, [
			'timeout' => 15,
			'redirection' => 5,
			'user-agent' => 'Mozilla/5.0 (compatible; EuroPulse AutoPilot)',
		]);

		if (is_wp_error($response)) {
			return $url;
		}

		$final = wp_remote_retrieve_header($response, 'x-final-url');
		if (is_string($final) && $final !== '') {
			return $final;
		}

		$effective = wp_remote_retrieve_header($response, 'location');
		return is_string($effective) && $effective !== '' ? $effective : $url;
	}
}
