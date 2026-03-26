<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Installer {
	public static function activate(): void {
		self::create_tables();
		add_option('epv2_installed_at', current_time('mysql'));
		EPV2_Capabilities::maybe_grant_caps();
		EPV2_Settings::set_all(EPV2_Settings::get_all());
		EPV2_Jobs::schedule_recurring();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		EPV2_Jobs::clear_scheduled();
		flush_rewrite_rules();
	}

	public static function uninstall(): void {
		// Keep data by default.
	}

	public static function maybe_upgrade_schema(): void {
		self::create_tables();
	}

	private static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;
		$tables = [];

		$tables[] = "CREATE TABLE {$prefix}epv2_sources (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			type VARCHAR(32) NOT NULL DEFAULT 'rss',
			url TEXT NOT NULL,
			language VARCHAR(8) NOT NULL DEFAULT 'de',
			category_bias VARCHAR(64) NOT NULL DEFAULT '',
			priority TINYINT NOT NULL DEFAULT 5,
			fetch_interval INT NOT NULL DEFAULT 1800,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
			parse_rules LONGTEXT NULL,
			attribution_rule LONGTEXT NULL,
			robots_status VARCHAR(32) NULL,
			last_fetched DATETIME NULL,
			last_error LONGTEXT NULL,
			notes LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY type (type),
			KEY is_active (is_active),
			KEY language (language)
		) {$charset};";

		$tables[] = "CREATE TABLE {$prefix}epv2_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT UNSIGNED NULL,
			cluster_id BIGINT UNSIGNED NULL,
			state VARCHAR(32) NOT NULL DEFAULT 'new',
			mode VARCHAR(16) NOT NULL DEFAULT 'semi',
			story_format VARCHAR(32) NULL,
			topic_label VARCHAR(255) NULL,
			story_score INT NOT NULL DEFAULT 0,
			language_plan VARCHAR(64) NOT NULL DEFAULT 'de,uk,en',
			original_url TEXT NOT NULL,
			canonical_url TEXT NULL,
			original_title TEXT NOT NULL,
			original_content LONGTEXT NULL,
			original_excerpt TEXT NULL,
			original_date DATETIME NULL,
			original_author VARCHAR(255) NULL,
			source_image_url TEXT NULL,
			title_hash CHAR(64) NULL,
			content_hash CHAR(64) NULL,
			semantic_hash CHAR(64) NULL,
			duplicate_of BIGINT UNSIGNED NULL,
			duplicate_reason VARCHAR(64) NULL,
			category_proposed VARCHAR(64) NULL,
			category_final VARCHAR(64) NULL,
			tags_proposed TEXT NULL,
			tags_final TEXT NULL,
			ai_payload LONGTEXT NULL,
			ai_provider VARCHAR(64) NULL,
			ai_model VARCHAR(128) NULL,
			ai_tokens INT NOT NULL DEFAULT 0,
			ai_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
			publish_payload LONGTEXT NULL,
			post_id BIGINT UNSIGNED NULL,
			error_message LONGTEXT NULL,
			admin_notes LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY state (state),
				KEY state_updated_at (state, updated_at),
				KEY state_created_at (state, created_at),
				KEY source_id (source_id),
				KEY cluster_id (cluster_id),
				KEY story_format (story_format),
				KEY topic_label (topic_label(191)),
				KEY category_final (category_final),
				KEY category_final_state (category_final, state),
				KEY duplicate_of (duplicate_of)
			) {$charset};";

		$tables[] = "CREATE TABLE {$prefix}epv2_clusters (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cluster_key CHAR(40) NOT NULL,
			title_seed TEXT NOT NULL,
			topic_label VARCHAR(255) NULL,
			primary_category VARCHAR(64) NULL,
			language_hint VARCHAR(8) NULL,
			mentions_24h INT NOT NULL DEFAULT 0,
			mentions_7d INT NOT NULL DEFAULT 0,
			source_count_24h INT NOT NULL DEFAULT 0,
			source_count_7d INT NOT NULL DEFAULT 0,
			developing_candidate TINYINT(1) NOT NULL DEFAULT 0,
			analysis_candidate TINYINT(1) NOT NULL DEFAULT 0,
			last_promoted_developing DATETIME NULL,
			last_promoted_analysis DATETIME NULL,
			first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY cluster_key (cluster_key),
			KEY topic_label (topic_label),
			KEY primary_category (primary_category),
			KEY developing_candidate (developing_candidate),
			KEY analysis_candidate (analysis_candidate),
			KEY last_seen_at (last_seen_at)
		) {$charset};";

		$tables[] = "CREATE TABLE {$prefix}epv2_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(16) NOT NULL DEFAULT 'info',
			module VARCHAR(64) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY level (level),
			KEY module (module),
			KEY created_at (created_at)
		) {$charset};";

		$tables[] = "CREATE TABLE {$prefix}epv2_stats (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			metric_date DATE NOT NULL,
			collected INT NOT NULL DEFAULT 0,
			rewritten INT NOT NULL DEFAULT 0,
			published INT NOT NULL DEFAULT 0,
			duplicates INT NOT NULL DEFAULT 0,
			errors INT NOT NULL DEFAULT 0,
			ai_tokens INT NOT NULL DEFAULT 0,
			ai_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY metric_date (metric_date)
		) {$charset};";

		$tables[] = "CREATE TABLE {$prefix}epv2_runs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_name VARCHAR(128) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'started',
			item_count INT NOT NULL DEFAULT 0,
			error_count INT NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			finished_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY job_name (job_name),
				KEY status (status),
				KEY job_status_started_at (job_name, status, started_at),
				KEY started_at (started_at)
			) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ($tables as $sql) {
			dbDelta($sql);
		}
	}
}
