<?php
/**
 * EPV2 pulse-mode status digest.
 *
 * Usage: sudo -u www-data wp eval-file scripts/epv2_pulse_status.php
 *        [--json]    machine-readable output
 *        [--quiet]   suppress headers, only emit the body
 *
 * Designed for operators running discrete pulses while automation is
 * paused: prints pause flags, queue counts by state, recent run/publish
 * activity, provider health, locks, and selection-audit pulse outcomes.
 * Read-only; never mutates options or rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress (via wp eval-file).\n" );
	exit( 1 );
}

global $wpdb, $argv;
$flags = is_array( $argv ?? null ) ? $argv : [];
$env_json  = getenv( 'EPV2_PULSE_JSON' );
$env_quiet = getenv( 'EPV2_PULSE_QUIET' );
$as_json   = in_array( '--json', $flags, true ) || ( $env_json !== false && $env_json !== '' && $env_json !== '0' );
$quiet     = in_array( '--quiet', $flags, true ) || ( $env_quiet !== false && $env_quiet !== '' && $env_quiet !== '0' );

$queue_table = $wpdb->prefix . 'epv2_queue';
$audit_table = $wpdb->prefix . 'epv2_selection_audit';
$runs_table  = $wpdb->prefix . 'epv2_runs';
$sources_tbl = $wpdb->prefix . 'epv2_sources';

$now_ts = (int) time();

$state_counts = [];
$rows_state = (array) $wpdb->get_results( "SELECT state, COUNT(*) AS c FROM {$queue_table} GROUP BY state ORDER BY c DESC" );
foreach ( $rows_state as $row ) {
	$state_counts[ (string) $row->state ] = (int) $row->c;
}
$queue_total = array_sum( $state_counts );

$last_publish = $wpdb->get_var( "SELECT MAX(post_date_gmt) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
$publish_24h  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR" );
$publish_7d   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 7 DAY" );

$audit_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" );
$audit_first = (string) $wpdb->get_var( "SELECT MIN(created_at) FROM {$audit_table}" );
$audit_last  = (string) $wpdb->get_var( "SELECT MAX(created_at) FROM {$audit_table}" );

$audit_outcomes = [];
foreach ( (array) $wpdb->get_results( "SELECT outcome, COUNT(*) AS c FROM {$audit_table} GROUP BY outcome ORDER BY c DESC" ) as $row ) {
	$audit_outcomes[ (string) $row->outcome ] = (int) $row->c;
}

$audit_24h = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table} WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR" );
$audit_24h_outcomes = [];
foreach ( (array) $wpdb->get_results( "SELECT outcome, COUNT(*) AS c FROM {$audit_table} WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR GROUP BY outcome ORDER BY c DESC" ) as $row ) {
	$audit_24h_outcomes[ (string) $row->outcome ] = (int) $row->c;
}

$run_recent = (array) $wpdb->get_results( "SELECT id, job_name, status, item_count, error_count, started_at, finished_at FROM {$runs_table} ORDER BY started_at DESC LIMIT 5" );
$run_recent = array_map( static function ( $r ) {
	return [
		'id' => (int) ( $r->id ?? 0 ),
		'job' => (string) ( $r->job_name ?? '' ),
		'status' => (string) ( $r->status ?? '' ),
		'items' => (int) ( $r->item_count ?? 0 ),
		'errors' => (int) ( $r->error_count ?? 0 ),
		'started_at' => (string) ( $r->started_at ?? '' ),
		'finished_at' => (string) ( $r->finished_at ?? '' ),
	];
}, $run_recent );

$source_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sources_tbl} WHERE is_active = 1" );
$source_total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sources_tbl}" );

$pause_automation = (int) get_option( 'epv2_automation_paused', 0 );
$pause_collect    = (int) get_option( 'epv2_collect_paused', 0 );

$settings = (array) get_option( 'epv2_settings', [] );

$ai_provider = (string) ( $settings['ai_provider'] ?? '' );
$ai_model    = (string) ( $settings['ai_model'] ?? '' );
$ai_fp       = (string) ( $settings['ai_fallback_provider'] ?? '' );
$ai_fm       = (string) ( $settings['ai_fallback_model'] ?? '' );

$provider_health = (array) get_option( 'epv2_provider_health', [] );
$provider_summary = [];
foreach ( $provider_health as $key => $entry ) {
	$entry = (array) $entry;
	$provider_summary[ (string) $key ] = [
		'consecutive_failures' => (int) ( $entry['consecutive_failures'] ?? 0 ),
		'cooldown_until' => (int) ( $entry['cooldown_until'] ?? 0 ),
		'cooldown_seconds_left' => max( 0, ( (int) ( $entry['cooldown_until'] ?? 0 ) ) - $now_ts ),
		'last_error' => mb_substr( (string) ( $entry['last_error'] ?? '' ), 0, 120 ),
	];
}

$tuning_snapshot_present = (bool) get_option( 'epv2_settings_pre_tuning_snapshot_20260505', false );

$cron_events = [];
if ( function_exists( '_get_cron_array' ) ) {
	$cron = _get_cron_array();
	foreach ( (array) $cron as $time => $hooks ) {
		foreach ( (array) $hooks as $hook => $details ) {
			if ( strpos( (string) $hook, 'epv2_' ) === 0 ) {
				$cron_events[] = [
					'hook' => (string) $hook,
					'when' => gmdate( 'Y-m-d H:i:s', (int) $time ) . 'Z',
					'in_seconds' => max( 0, (int) $time - $now_ts ),
				];
			}
		}
	}
}

$lock_status = [];
if ( class_exists( 'EPV2_Lock_Manager' ) ) {
	foreach ( [ 'collect', 'process', 'publish' ] as $name ) {
		$snap = EPV2_Lock_Manager::status_snapshot( $name );
		$lock_status[ $name ] = [
			'held' => ! empty( $snap['held'] ),
			'owner' => (string) ( $snap['owner_token'] ?? '' ),
			'age_seconds' => (int) ( $snap['age_seconds'] ?? 0 ),
		];
	}
}

$queue_size_avg = (int) $wpdb->get_var( "SELECT IFNULL(AVG(LENGTH(ai_payload)), 0) FROM {$queue_table} WHERE ai_payload IS NOT NULL" );
$queue_size_max = (int) $wpdb->get_var( "SELECT IFNULL(MAX(LENGTH(ai_payload)), 0) FROM {$queue_table} WHERE ai_payload IS NOT NULL" );

$stage_counts = [];
foreach ( (array) $wpdb->get_results( "SELECT pipeline_stage, COUNT(*) AS c FROM {$queue_table} WHERE pipeline_stage IS NOT NULL AND pipeline_stage <> '' GROUP BY pipeline_stage ORDER BY c DESC" ) as $row ) {
	$stage_counts[ (string) $row->pipeline_stage ] = (int) $row->c;
}

$digest = [
	'now_utc' => gmdate( 'Y-m-d H:i:s', $now_ts ) . 'Z',
	'pause' => [
		'automation_paused' => $pause_automation,
		'collect_paused' => $pause_collect,
		'tuning_snapshot_present' => $tuning_snapshot_present,
	],
	'queue' => [
		'total' => $queue_total,
		'by_state' => $state_counts,
		'by_pipeline_stage' => $stage_counts,
		'ai_payload_avg_bytes' => $queue_size_avg,
		'ai_payload_max_bytes' => $queue_size_max,
	],
	'publish' => [
		'last_published_at' => (string) $last_publish,
		'count_24h' => $publish_24h,
		'count_7d' => $publish_7d,
	],
	'sources' => [
		'active' => $source_active,
		'total' => $source_total,
	],
	'ai' => [
		'primary_provider' => $ai_provider,
		'primary_model' => $ai_model,
		'fallback_provider' => $ai_fp,
		'fallback_model' => $ai_fm,
		'health' => $provider_summary,
	],
	'selection_audit' => [
		'rows_total' => $audit_rows,
		'first' => $audit_first,
		'last' => $audit_last,
		'outcomes_total' => $audit_outcomes,
		'rows_24h' => $audit_24h,
		'outcomes_24h' => $audit_24h_outcomes,
	],
	'runs_recent' => $run_recent,
	'cron_events' => $cron_events,
	'locks' => $lock_status,
];

if ( $as_json ) {
	echo wp_json_encode( $digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
	return;
}

$out = [];
if ( ! $quiet ) {
	$out[] = "EPV2 pulse status @ " . $digest['now_utc'];
	$out[] = str_repeat( '-', 60 );
}

$out[] = sprintf(
	'pause: automation=%d collect=%d tuning_snapshot=%s',
	$digest['pause']['automation_paused'],
	$digest['pause']['collect_paused'],
	$digest['pause']['tuning_snapshot_present'] ? 'present' : 'absent'
);

$out[] = sprintf( 'queue: %d rows (%s)', $queue_total, $queue_total === 0 ? 'empty' : http_build_query( $state_counts, '', ', ' ) );
if ( $stage_counts !== [] ) {
	$out[] = '  pipeline_stage: ' . http_build_query( $stage_counts, '', ', ' );
}
if ( $queue_size_max > 0 ) {
	$out[] = sprintf( '  ai_payload bytes: avg=%d max=%d', $queue_size_avg, $queue_size_max );
}

$out[] = sprintf(
	'publish: last=%s count_24h=%d count_7d=%d',
	$last_publish ?: '—',
	$publish_24h,
	$publish_7d
);

$out[] = sprintf( 'sources: %d active / %d total', $source_active, $source_total );
$out[] = sprintf( 'ai primary: %s / %s   fallback: %s / %s', $ai_provider, $ai_model, $ai_fp, $ai_fm );

if ( $provider_summary !== [] ) {
	foreach ( $provider_summary as $name => $info ) {
		$cooldown = $info['cooldown_seconds_left'] > 0 ? sprintf( 'cooldown_left=%ds', $info['cooldown_seconds_left'] ) : 'cooldown=ok';
		$out[] = sprintf( '  health[%s]: failures=%d %s last_error=%s', $name, $info['consecutive_failures'], $cooldown, $info['last_error'] ?: '—' );
	}
}

$out[] = sprintf( 'audit: total=%d span=[%s..%s]', $audit_rows, $audit_first ?: '—', $audit_last ?: '—' );
if ( $audit_24h > 0 ) {
	$out[] = sprintf( '  last 24h: %d rows (%s)', $audit_24h, http_build_query( $audit_24h_outcomes, '', ', ' ) );
}

if ( $cron_events !== [] ) {
	$out[] = 'cron events:';
	foreach ( $cron_events as $ev ) {
		$out[] = sprintf( '  %s @ %s (in %ds)', $ev['hook'], $ev['when'], $ev['in_seconds'] );
	}
} else {
	$out[] = 'cron events: none (server-orchestrator mode)';
}

if ( $lock_status !== [] ) {
	$out[] = 'locks:';
	foreach ( $lock_status as $name => $info ) {
		$out[] = sprintf( '  %s: %s%s', $name, $info['held'] ? 'HELD' : 'free', $info['held'] ? sprintf( ' age=%ds owner=%s', $info['age_seconds'], $info['owner'] ?: '—' ) : '' );
	}
}

if ( $run_recent !== [] ) {
	$out[] = 'recent runs:';
	foreach ( $run_recent as $r ) {
		$out[] = sprintf( '  %d %s [%s] items=%d errors=%d %s..%s', $r['id'], $r['job'], $r['status'], $r['items'], $r['errors'], $r['started_at'] ?: '—', $r['finished_at'] ?: '—' );
	}
}

echo implode( "\n", $out ), "\n";
