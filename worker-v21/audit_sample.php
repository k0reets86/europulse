<?php
/**
 * Выгрузка выборки свежеопубликованных статей для ежедневного аудита достоверности.
 * Вывод: JSON-массив {qid, de_title, de_lead, de_card, de_body, uk, primary, supporting}.
 * Запускается через `wp eval-file` из daily_audit.py.
 */
$limit = (int) (getenv('EPV2_AUDIT_SAMPLE') ?: 12);
$rows = $GLOBALS['wpdb']->get_results(
    "SELECT id, ai_payload FROM ep_epv2_queue
     WHERE state='published' AND updated_at >= (NOW() - INTERVAL 24 HOUR)
     ORDER BY id DESC LIMIT 60"
);
$out = [];
foreach ($rows as $r) {
    if (count($out) >= $limit) break;
    $p = json_decode($r->ai_payload, true);
    if (!is_array($p)) continue;
    $de = $p['languages']['de'] ?? [];
    $uk = $p['languages']['uk'] ?? [];
    $primary = $p['_meta']['source_dossier']['primary']['content'] ?? '';
    $supp = '';
    foreach (($p['_meta']['source_dossier']['supporting'] ?? []) as $s) {
        $c = $s['content'] ?? '';
        if (mb_strlen($c) >= 200) $supp .= ($supp ? "\n---\n" : '') . mb_substr($c, 0, 2500);
    }
    $ukblob = '';
    foreach (['lead','card_lead','body','content'] as $k) {
        if (!empty($uk[$k]) && is_string($uk[$k])) $ukblob .= $uk[$k] . "\n";
    }
    if (empty($de['body']) && empty($de['content'])) continue;
    $out[] = [
        'qid'       => (int) $r->id,
        'de_title'  => (string) ($de['title'] ?? ''),
        'de_lead'   => (string) ($de['lead'] ?? ''),
        'de_card'   => (string) ($de['card_lead'] ?? ''),
        'de_body'   => (string) ($de['body'] ?? $de['content'] ?? ''),
        'uk'        => trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($ukblob))),
        'primary'   => mb_substr($primary, 0, 9000),
        'supporting'=> mb_substr($supp, 0, 5000),
    ];
}
echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
