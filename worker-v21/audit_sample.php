<?php
/**
 * Выборка свежеопубликованных ЖИВЫХ постов для ежедневного аудита достоверности.
 * 2026-07-03: ПЕРЕПИСАНО — раньше брали из ep_epv2_queue, но она агрессивно прунится
 * после публикации → аудит видел лишь ~30% контента (61 из 204/сутки) и логировал qid
 * (queue id, который не открыть — резолвился в ревизии/вложения). Теперь источник —
 * ЖИВЫЕ DE-посты (ep_posts), ключ находок = post_id (открываемый), + url и source_url.
 * Вывод: JSON-массив {post_id, qid, url, source_url, de_title, de_lead, de_card, de_body, uk, en, primary, supporting}.
 * Запускается через `wp eval-file` из daily_audit.py.
 */
$limit = (int) (getenv('EPV2_AUDIT_SAMPLE') ?: 300);
$hours = (int) (getenv('EPV2_AUDIT_HOURS') ?: 24);

$wpdb = $GLOBALS['wpdb'];
$post_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_type='post' AND post_status='publish'
       AND post_date >= (NOW() - INTERVAL %d HOUR)
     ORDER BY ID DESC LIMIT 1200",
    $hours
));

$strip = static function (string $html): string {
    $html = preg_replace('/<!--\s*\/?wp:html\s*-->/i', '', $html);
    $html = preg_replace('/<figure\b.*?<\/figure>/is', '', (string) $html);
    return trim((string) wp_strip_all_tags((string) $html));
};
$blob = static function ($pid) use ($strip): string {
    $pid = (int) $pid;
    if ($pid <= 0) {
        return '';
    }
    $p = get_post($pid);
    if (! $p) {
        return '';
    }
    return trim((string) preg_replace('/\s+/u', ' ', $p->post_title . "\n" . $p->post_excerpt . "\n" . $strip((string) $p->post_content)));
};

$out = [];
$seen = [];
foreach ($post_ids as $pid) {
    if (count($out) >= $limit) {
        break;
    }
    $pid = (int) $pid;
    $tr = function_exists('pll_get_post_translations') ? pll_get_post_translations($pid) : ['de' => $pid];
    $de_id = (int) ($tr['de'] ?? 0);
    if ($de_id <= 0) {
        $lang = function_exists('pll_get_post_language') ? pll_get_post_language($pid) : 'de';
        $de_id = ($lang === 'de' || $lang === '' || $lang === false) ? $pid : 0;
    }
    if ($de_id <= 0) {
        continue; // не-DE пост без DE-перевода — пропускаем, DE придёт своим ходом
    }
    if (isset($seen[$de_id])) {
        continue;
    }
    $seen[$de_id] = 1;
    $de = get_post($de_id);
    if (! $de) {
        continue;
    }
    $de_body = $strip((string) $de->post_content);
    if (mb_strlen($de_body) < 40) {
        continue;
    }
    $purl = (string) get_post_meta($de_id, '_epv2_source_url', true);
    $primary = '';
    if ($purl !== '' && class_exists('EPV2_HTML_Reader') && filter_var($purl, FILTER_VALIDATE_URL)) {
        try {
            $doc = EPV2_HTML_Reader::fetch_document($purl);
            $primary = (string) ($doc['content'] ?? '');
        } catch (Throwable $e) {
            // источник недоступен (403/paywall) — оставляем пустым: hall-судья тогда
            // ПРОПУСКАЕТ проверку (порог 350), чтобы не флагать реальное как выдумку.
        }
    }
    $out[] = [
        'post_id'    => $de_id,
        'qid'        => 0, // очередь прунится; ключ находок теперь post_id (см. daily_audit)
        'url'        => (string) get_permalink($de_id),
        'source_url' => $purl,
        'de_title'   => (string) $de->post_title,
        'de_lead'    => (string) $de->post_excerpt,
        'de_card'    => '',
        'de_body'    => $de_body,
        'uk'         => $blob($tr['uk'] ?? 0),
        'en'         => $blob($tr['en'] ?? 0),
        'primary'    => mb_substr($primary, 0, 9000),
        'supporting' => '',
    ];
}
echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
