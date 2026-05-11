<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Logger {
	private const PRUNE_DEFAULT_DAYS = 30;
	private const PRUNE_HOOK = 'epv2_logger_prune_daily';

	public static function register(): void {
		add_action(self::PRUNE_HOOK, [self::class, 'run_scheduled_prune']);
		// Регистрация WP-cron'а — бежит раз в день. Используется
		// activation hook'ом плагина, но также сами schedule'им если
		// хук ещё не активен (на случай первой загрузки после деплоя).
		if (! wp_next_scheduled(self::PRUNE_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
		}
	}

	public static function log(string $level, string $module, string $message, array $context = []): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'epv2_log',
			[
				'level' => $level,
				'module' => $module,
				'message' => $message,
				'context' => ! empty($context) ? wp_json_encode($context, JSON_UNESCAPED_UNICODE) : null,
			]
		);
	}

	public static function info(string $module, string $message, array $context = []): void {
		self::log('info', $module, $message, $context);
	}

	public static function warning(string $module, string $message, array $context = []): void {
		self::log('warning', $module, $message, $context);
	}

	public static function error(string $module, string $message, array $context = []): void {
		self::log('error', $module, $message, $context);
	}

	/**
	 * Удаляет записи старше N дней. По умолчанию 30 дней — операционный
	 * лог не нужен дальше для дебага, и его рост (~5MB/день) на длинной
	 * дистанции забивает БД.
	 *
	 * @return int количество удалённых строк
	 */
	public static function prune(int $days = self::PRUNE_DEFAULT_DAYS): int {
		global $wpdb;
		$days = max(1, $days);
		$deleted = (int) $wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}epv2_log WHERE created_at < (NOW() - INTERVAL %d DAY)",
			$days
		));
		// OPTIMIZE TABLE после крупного DELETE — высвобождает место в
		// файловой системе. Делаем только если удалили > 1000 строк.
		if ($deleted > 1000) {
			$wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}epv2_log");
		}
		return $deleted;
	}

	public static function run_scheduled_prune(): void {
		try {
			// Читаем retention из Settings; default 14d (override of class const 30).
			// Базы 350K rows в ep_epv2_log = 84MB при 30d. На 14d держим ~150K rows.
			$days = (int) (class_exists('EPV2_Settings') ? EPV2_Settings::get('log_retention_days', 14) : 14);
			$deleted = self::prune($days);
			if ($deleted > 0) {
				self::info('logger.prune', 'Daily log prune', [
					'deleted_rows' => $deleted,
					'retention_days' => $days,
				]);
			}
			// ep_epv2_runs тоже не имеет cleanup — 22K rows накопилось. Чистим
			// руны старше 7d (нужны только для дебага свежих ran-failures).
			global $wpdb;
			$runs_deleted = (int) $wpdb->query("DELETE FROM {$wpdb->prefix}epv2_runs WHERE started_at < (NOW() - INTERVAL 7 DAY) LIMIT 5000");
			if ($runs_deleted > 0) {
				self::info('logger.prune', 'Runs prune', [
					'deleted_rows' => $runs_deleted,
					'retention_days' => 7,
				]);
			}
			// ep_epv2_selection_audit тоже не имеет cleanup — 10K rows.
			$audit_deleted = (int) $wpdb->query("DELETE FROM {$wpdb->prefix}epv2_selection_audit WHERE created_at < (NOW() - INTERVAL 7 DAY) LIMIT 5000");
			if ($audit_deleted > 0) {
				self::info('logger.prune', 'Selection audit prune', [
					'deleted_rows' => $audit_deleted,
					'retention_days' => 7,
				]);
			}
			// OPTIMIZE TABLE для всех epv2 таблиц (compress free space после
			// массовых DELETE — фрагментация может достигать 600%+ после
			// daily prune, диск не освобождается без OPTIMIZE).
			foreach (['epv2_log','epv2_runs','epv2_selection_audit','epv2_queue','epv2_clusters'] as $tbl) {
				$wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}{$tbl}");
			}
			// Orphan attachment cleanup — pipeline downloads media затем
			// attach fail / item rejected → файлы остаются. За неделю
			// накапливается ~600 unused (~450MB). Critical safety filters:
			// (1) post_parent=0, (2) не _thumbnail_id, (3) URL не в post_content
			// любого опубликованного поста, (4) age >24h (pipeline ещё attach'ит).
			$attach_deleted = self::prune_unused_attachments(500);
			if ($attach_deleted > 0) {
				self::info('logger.prune', 'Orphan attachments prune', [
					'deleted_attachments' => $attach_deleted,
				]);
			}
		} catch (Throwable $e) {
			error_log('[epv2_logger_prune] failed: ' . $e->getMessage());
		}
	}

	/**
	 * Удаляет orphan attachments которые не используются:
	 * - post_parent=0
	 * - не _thumbnail_id ни одного поста
	 * - URL не встречается в post_content
	 * - старше 24h (pipeline ещё может attach'ить in-flight items)
	 *
	 * Возвращает count реально удалённых.
	 */
	public static function prune_unused_attachments(int $limit = 500): int {
		global $wpdb;
		$candidates = $wpdb->get_col($wpdb->prepare(
			"SELECT a.ID FROM {$wpdb->posts} a
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_value=a.ID AND pm.meta_key='_thumbnail_id'
			 WHERE a.post_type='attachment'
			   AND a.post_parent=0
			   AND pm.meta_id IS NULL
			   AND a.post_date_gmt < DATE_SUB(NOW(), INTERVAL 24 HOUR)
			 LIMIT %d",
			max(1, min(2000, $limit))
		));
		if (empty($candidates)) return 0;
		$deleted = 0;
		foreach ($candidates as $aid) {
			$aid = (int) $aid;
			$url = wp_get_attachment_url($aid);
			if (! $url) continue;
			$rel = preg_replace('#^.*?/uploads/#', '', $url);
			if (! $rel) continue;
			// Защита: URL в любом post_content?
			$hits = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_status='publish' AND post_content LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like((string) $rel) . '%'
			));
			if ($hits > 0) continue;
			if (wp_delete_attachment($aid, true)) {
				$deleted++;
			}
		}
		return $deleted;
	}
}
