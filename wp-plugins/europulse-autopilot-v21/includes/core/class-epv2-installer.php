<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Installer {
	public static function activate(): void {
		self::create_tables();
		add_option('epv2_installed_at', current_time('mysql'));
		update_option('epv2_version', EPV2_VERSION, false);
		update_option('epv2_server_orchestrator_enabled', 1, false);
		EPV2_Capabilities::maybe_grant_caps();
		EPV2_Settings::set_all(EPV2_Settings::get_all());
		EPV2_Jobs::schedule_recurring();
		EPV2_Weekly_Analysis::maybe_schedule();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		EPV2_Jobs::clear_scheduled();
		wp_clear_scheduled_hook('epv2_weekly_analysis');
		flush_rewrite_rules();
	}

	public static function uninstall(): void {
		// Keep data by default.
	}

	public static function maybe_upgrade_schema(): void {
		self::create_tables();
		update_option('epv2_version', EPV2_VERSION, false);
		EPV2_Capabilities::maybe_grant_caps_once();
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

		$tables[] = "CREATE TABLE {$prefix}epv2_selection_audit (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id BIGINT UNSIGNED NULL,
			source_id BIGINT UNSIGNED NULL,
			candidate_hash CHAR(40) NOT NULL,
			phase VARCHAR(32) NOT NULL DEFAULT 'stage',
			outcome VARCHAR(64) NOT NULL DEFAULT '',
			decision VARCHAR(32) NOT NULL DEFAULT '',
			tier VARCHAR(8) NOT NULL DEFAULT '',
			score INT NOT NULL DEFAULT 0,
			category VARCHAR(64) NOT NULL DEFAULT '',
			reject_class VARCHAR(64) NOT NULL DEFAULT '',
			source_name VARCHAR(255) NOT NULL DEFAULT '',
			source_type VARCHAR(32) NOT NULL DEFAULT '',
			source_priority TINYINT NOT NULL DEFAULT 0,
			source_risk VARCHAR(16) NOT NULL DEFAULT '',
			original_url TEXT NULL,
			original_title TEXT NOT NULL,
			original_date DATETIME NULL,
			reason TEXT NULL,
			selection_json LONGTEXT NULL,
			ai_gate_json LONGTEXT NULL,
			planner_json LONGTEXT NULL,
			context_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY queue_id (queue_id),
			KEY candidate_hash (candidate_hash),
			KEY source_id (source_id),
			KEY category (category),
			KEY decision (decision),
			KEY outcome (outcome),
			KEY created_at (created_at)
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

		self::ensure_queue_generated_columns();
	}

	/**
	 * Add MariaDB/MySQL generated columns to ep_epv2_queue that mirror
	 * frequently filtered fields inside ai_payload._meta. dbDelta does
	 * not support GENERATED ... AS, so we add them with idempotent
	 * ALTER TABLEs guarded by information_schema lookups.
	 *
	 * Currently exposes:
	 *   - pipeline_stage: VARCHAR(64) STORED, indexed. Replaces costly
	 *     LIKE '%"pipeline_stage":"..."%' scans on the LONGTEXT payload
	 *     in repair / maintenance functions.
	 */
	private static function ensure_queue_generated_columns(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$db    = defined('DB_NAME') ? DB_NAME : (string) $wpdb->dbname;

		$column_exists = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.columns
				WHERE table_schema = %s AND table_name = %s AND column_name = %s",
			$db,
			$table,
			'pipeline_stage'
		));
		if ($column_exists === 0) {
			// STORED so the value can be indexed and read without
			// re-extracting from the JSON LONGTEXT on every query.
			$wpdb->query(
				"ALTER TABLE {$table} ADD COLUMN pipeline_stage VARCHAR(64) AS ("
				. "JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.pipeline_stage'))"
				. ") STORED"
			);
		}

		$index_exists = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.statistics
				WHERE table_schema = %s AND table_name = %s AND index_name = %s",
			$db,
			$table,
			'idx_pipeline_stage'
		));
		if ($index_exists === 0) {
			$wpdb->query("ALTER TABLE {$table} ADD INDEX idx_pipeline_stage (pipeline_stage)");
		}
	}
}
