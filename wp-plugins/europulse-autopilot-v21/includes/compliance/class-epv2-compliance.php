<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Compliance {
	public static function append_source_block(string $content, string $source_url, string $source_name, string $lang = 'de'): string {
		if (! EPV2_Settings::get('source_block_enabled', true) || $source_url === '') {
			return $content;
		}

		$labels = ['de' => 'Quelle', 'uk' => 'Джерело', 'en' => 'Source'];
		$label = $labels[$lang] ?? 'Quelle';
		$source_name = $source_name ?: EPV2_Source_Adapters::source_name_for_url($source_url);
		$block = '<p><strong>' . esc_html($label) . ':</strong> <a href="' . esc_url($source_url) . '" target="_blank" rel="noopener nofollow">' . esc_html($source_name) . '</a></p>';
		if (str_contains(strtolower($source_url), 'ukrinform')) {
			return $block . "\n\n" . $content;
		}
		return $content . "\n\n" . $block;
	}
}
