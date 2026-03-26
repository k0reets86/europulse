<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Bootstrap {
	public static function init(): void {
		self::load_files();
		add_action('plugins_loaded', [EPV3_Plugin::class, 'init']);
		register_activation_hook(EPV3_PLUGIN_FILE, [EPV3_Installer::class, 'activate']);
		register_deactivation_hook(EPV3_PLUGIN_FILE, [EPV3_Installer::class, 'deactivate']);
	}

	private static function load_files(): void {
		$files = [
			'includes/core/class-epv3-installer.php',
			'includes/core/class-epv3-settings.php',
			'includes/core/class-epv3-stage-machine.php',
			'includes/core/class-epv3-queue-repository.php',
			'includes/core/class-epv3-runs-repository.php',
			'includes/core/class-epv3-knowledge-pack.php',
			'includes/core/class-epv3-categorizer.php',
			'includes/core/class-epv3-intake-filter.php',
			'includes/core/class-epv3-ai-client.php',
			'includes/core/class-epv3-context-analyzer.php',
			'includes/core/class-epv3-dossier-builder.php',
			'includes/core/class-epv3-de-master-builder.php',
			'includes/core/class-epv3-media-manager.php',
			'includes/core/class-epv3-translation-manager.php',
			'includes/core/class-epv3-quality-validator.php',
			'includes/core/class-epv3-seo-manager.php',
			'includes/core/class-epv3-publisher.php',
			'includes/core/class-epv3-orchestrator.php',
			'includes/core/class-epv3-runner.php',
			'includes/core/class-epv3-plugin.php',
		];

		foreach ($files as $relative) {
			require_once EPV3_PLUGIN_DIR . $relative;
		}
	}
}
