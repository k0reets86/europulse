<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Bootstrap {
	private static bool $booted = false;

	public static function init(): void {
		if (self::$booted) {
			return;
		}

		self::$booted = true;

		spl_autoload_register([self::class, 'autoload']);

		register_activation_hook(EPV2_PLUGIN_FILE, ['EPV2_Installer', 'activate']);
		register_deactivation_hook(EPV2_PLUGIN_FILE, ['EPV2_Installer', 'deactivate']);
		register_uninstall_hook(EPV2_PLUGIN_FILE, ['EPV2_Installer', 'uninstall']);

		add_action('plugins_loaded', ['EPV2_Plugin', 'load_textdomain']);
		add_action('plugins_loaded', ['EPV2_Plugin', 'boot'], 5);
	}

	public static function autoload(string $class): void {
		if (strpos($class, 'EPV2_') !== 0) {
			return;
		}

		$map = [
			'EPV2_Plugin' => 'core/class-epv2-plugin.php',
			'EPV2_Site_Profile' => 'core/class-epv2-site-profile.php',
			'EPV2_Settings' => 'core/class-epv2-settings.php',
			'EPV2_Lock_Manager' => 'core/class-epv2-lock-manager.php',
			'EPV2_Resilience_Manager' => 'core/class-epv2-resilience-manager.php',
			'EPV2_Source_Enricher' => 'core/class-epv2-source-enricher.php',
			'EPV2_Trends' => 'core/class-epv2-trends.php',
			'EPV2_Budget_Manager' => 'core/class-epv2-budget-manager.php',
			'EPV2_Category_Planner' => 'core/class-epv2-category-planner.php',
			'EPV2_Breaking_Engine' => 'core/class-epv2-breaking-engine.php',
			'EPV2_Internal_Linker' => 'core/class-epv2-internal-linker.php',
			'EPV2_Time_Planner' => 'core/class-epv2-time-planner.php',
			'EPV2_News_Sitemap' => 'core/class-epv2-news-sitemap.php',
			'EPV2_Schema_Enricher' => 'seo/class-epv2-schema-enricher.php',
			'EPV2_Story_Clusters' => 'core/class-epv2-story-clusters.php',
			'EPV2_Capabilities' => 'core/class-epv2-capabilities.php',
			'EPV2_Installer' => 'core/class-epv2-installer.php',
			'EPV2_Upgrader' => 'core/class-epv2-upgrader.php',
			'EPV2_Worker_Client' => 'core/class-epv2-worker-client.php',

			'EPV2_Admin' => 'admin/class-epv2-admin.php',
			'EPV2_REST' => 'api/class-epv2-rest.php',
			'EPV2_Review' => 'review/class-epv2-review.php',

			'EPV2_Jobs' => 'jobs/class-epv2-jobs.php',
			'EPV2_Runs' => 'jobs/class-epv2-runs.php',

			'EPV2_Sources' => 'sources/class-epv2-sources.php',
			'EPV2_Source_Tester' => 'sources/class-epv2-source-tester.php',
			'EPV2_Source_Library' => 'sources/class-epv2-source-library.php',
			'EPV2_Source_Adapters' => 'sources/class-epv2-source-adapters.php',

			'EPV2_Queue' => 'queue/class-epv2-queue.php',
			'EPV2_Deduplicator' => 'queue/class-epv2-deduplicator.php',

			'EPV2_Feed_Reader' => 'ingest/class-epv2-feed-reader.php',
			'EPV2_Google_News' => 'ingest/class-epv2-google-news.php',
			'EPV2_HTML_Reader' => 'ingest/class-epv2-html-reader.php',
			'EPV2_Social_Reader' => 'ingest/class-epv2-social-reader.php',
			'EPV2_Collector' => 'ingest/class-epv2-collector.php',

			'EPV2_Taxonomy_Map' => 'classify/class-epv2-taxonomy-map.php',
			'EPV2_Categorizer' => 'classify/class-epv2-categorizer.php',

			'EPV2_AI_Processor' => 'ai/class-epv2-ai-processor.php',
			'EPV2_AI_Client' => 'ai/class-epv2-ai-client.php',
			'EPV2_AI_Response_Validator' => 'ai/class-epv2-ai-response-validator.php',
			'EPV2_Content_Kinds' => 'ai/class-epv2-content-kinds.php',
			'EPV2_Content_Filters' => 'ai/class-epv2-content-filters.php',
			'EPV2_Dossier_Enricher' => 'ai/class-epv2-dossier-enricher.php',
			'EPV2_Importance_Score' => 'ai/class-epv2-importance-score.php',
			'EPV2_Watchdog' => 'queue/class-epv2-watchdog.php',
			'EPV2_Notifier' => 'core/class-epv2-notifier.php',
			'EPV2_Story_Card_Builder' => 'ai/class-epv2-story-card-builder.php',
			'EPV2_Prompt_Profiles' => 'ai/class-epv2-prompt-profiles.php',
			'EPV2_AI_Analyzer' => 'ai/class-epv2-ai-analyzer.php',
			'EPV2_AI_Rewriter' => 'ai/class-epv2-ai-rewriter.php',
			'EPV2_AI_Translator' => 'ai/class-epv2-ai-translator.php',
			'EPV2_AI_SEO' => 'ai/class-epv2-ai-seo.php',
			'EPV2_Weekly_Analysis' => 'analytics/class-epv2-weekly-analysis.php',

			'EPV2_Media' => 'media/class-epv2-media.php',
			'EPV2_Compliance' => 'compliance/class-epv2-compliance.php',
			'EPV2_Publish_Gate' => 'publish/class-epv2-publish-gate.php',
			'EPV2_Publisher' => 'publish/class-epv2-publisher.php',
			'EPV2_Post_Audit' => 'publish/class-epv2-post-audit.php',
			'EPV2_Manual_Mode' => 'manual/class-epv2-manual-mode.php',

			'EPV2_Logger' => 'metrics/class-epv2-logger.php',
			'EPV2_Selection_Audit' => 'metrics/class-epv2-selection-audit.php',
			'EPV2_Stats' => 'metrics/class-epv2-stats.php',
		];

		if (! isset($map[$class])) {
			return;
		}

		$file = EPV2_PLUGIN_DIR . 'includes/' . $map[$class];
		if (file_exists($file)) {
			require_once $file;
		}
	}
}
