<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Installer {
	public static function activate(): void {
		self::create_tables();
		add_option('epv3_installed_at', current_time('mysql'));
		add_option('epv3_settings', EPV3_Settings::defaults(), '', false);
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook(EPV3_Orchestrator::HOOK);
	}

	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;

		$queue = "CREATE TABLE {$prefix}epv3_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			state VARCHAR(32) NOT NULL DEFAULT 'ingested',
			stage VARCHAR(32) NOT NULL DEFAULT 'ingested',
			priority INT NOT NULL DEFAULT 0,
			source_id BIGINT UNSIGNED NULL,
			original_url TEXT NOT NULL,
			original_language VARCHAR(12) NOT NULL DEFAULT '',
			original_title TEXT NOT NULL,
			original_excerpt TEXT NULL,
			original_content LONGTEXT NULL,
			source_image_url TEXT NULL,
			context_payload LONGTEXT NULL,
			dossier_payload LONGTEXT NULL,
			de_payload LONGTEXT NULL,
			uk_payload LONGTEXT NULL,
			en_payload LONGTEXT NULL,
			publish_payload LONGTEXT NULL,
			error_code VARCHAR(64) NULL,
			error_message LONGTEXT NULL,
			retry_after DATETIME NULL,
			publish_not_before DATETIME NULL,
			post_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY state (state),
			KEY stage (stage),
			KEY state_stage (state, stage),
			KEY state_updated_at (state, updated_at),
			KEY publish_not_before (publish_not_before)
		) {$charset};";

		$runs = "CREATE TABLE {$prefix}epv3_runs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_name VARCHAR(64) NOT NULL,
			queue_id BIGINT UNSIGNED NULL,
			stage VARCHAR(32) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'started',
			message TEXT NULL,
			payload LONGTEXT NULL,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			finished_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY job_name (job_name),
			KEY status (status),
			KEY queue_stage (queue_id, stage),
			KEY started_at (started_at)
		) {$charset};";

		dbDelta($queue);
		dbDelta($runs);
	}
}
