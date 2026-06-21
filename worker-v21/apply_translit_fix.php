<?php
/**
 * Детерминированный авто-фикс гарбленной транслитерации в живом UK-посте + payload.
 * Аргументы через env (избегаем shell-экранирования кириллицы):
 *   EPV2_FIX_POST  — DE post_id (для поиска UK-перевода через Polylang)
 *   EPV2_FIX_QID   — id строки очереди (для правки ai_payload)
 *   EPV2_FIX_WRONG — точная гарбленная подстрока
 *   EPV2_FIX_CORRECT — корректная латинская форма
 * Печатает FIXED при успехе. Вызывается из daily_audit.py.
 */
$post_id = (int) getenv('EPV2_FIX_POST');
$qid     = (int) getenv('EPV2_FIX_QID');
$wrong   = (string) getenv('EPV2_FIX_WRONG');
$correct = (string) getenv('EPV2_FIX_CORRECT');
if ($wrong === '' || $correct === '' || $wrong === $correct) { echo "SKIP no-args"; return; }

$did = false;

// 1) Живой UK-пост (Polylang-перевод DE-поста).
$uk_post = 0;
if ($post_id && function_exists('pll_get_post_translations')) {
    $tr = pll_get_post_translations($post_id);
    $uk_post = (int) ($tr['uk'] ?? 0);
}
if ($uk_post) {
    $p = get_post($uk_post);
    if ($p) {
        $new = str_replace($wrong, $correct, $p->post_content);
        if ($new !== $p->post_content) {
            wp_update_post(['ID' => $uk_post, 'post_content' => $new]);
            clean_post_cache($uk_post);
            $did = true;
        }
    }
}

// 2) ai_payload строки очереди (languages.uk) — чтобы перезамеры были консистентны.
if ($qid) {
    $raw = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        "SELECT ai_payload FROM ep_epv2_queue WHERE id=%d", $qid));
    if (is_string($raw) && $raw !== '' && mb_strpos($raw, $wrong) !== false) {
        $fixed = str_replace($wrong, $correct, $raw);
        $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
            "UPDATE ep_epv2_queue SET ai_payload=%s WHERE id=%d", $fixed, $qid));
        $did = true;
    }
}

// nginx FastCGI-кэш для затронутого поста
if ($did) {
    $cache = '/var/cache/nginx/europulse';
    if (is_dir($cache)) { @array_map('unlink', (array) glob($cache . '/*')); }
}

echo $did ? "FIXED uk_post=$uk_post" : "NOCHANGE";
