<?php
/**
 * Plugin Name: EuroPulse Foundation
 * Description: Lightweight structural helpers and styling hooks for the EuroPulse newsroom base.
 */

if (! defined('ABSPATH')) {
	exit;
}

$europulse_foundation_modules = [
	__DIR__ . '/europulse-foundation/includes/core.php',
	__DIR__ . '/europulse-foundation/includes/front-hooks.php',
	__DIR__ . '/europulse-foundation/includes/render.php',
	__DIR__ . '/europulse-foundation/includes/content-seo-hooks.php',
];

foreach ($europulse_foundation_modules as $europulse_foundation_module) {
	if (file_exists($europulse_foundation_module)) {
		require_once $europulse_foundation_module;
	}
}

unset($europulse_foundation_modules, $europulse_foundation_module);
