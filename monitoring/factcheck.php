<?php
// Fact-check published items today: numbers, names, media mismatches.
// Usage: cd /var/www/europulse/public && sudo -u www-data wp eval-file /root/projects/europulse/monitoring/factcheck.php

global $wpdb;
$rows = $wpdb->get_results("
SELECT id, category_final, original_title, original_content, original_url, source_image_url, ai_payload, post_id
FROM {$wpdb->prefix}epv2_queue
WHERE state='published' AND updated_at >= '2026-05-11 00:00:00'
ORDER BY id ASC
");

$findings = [];
$count = 0;

function extract_numbers($text) {
    if (!is_string($text)) return [];
    // Match: integers ≥ 100, decimals, percentages, years 1900-2099, dates
    preg_match_all('/\b\d{1,3}(?:[\s.,]\d{3})+(?:[,.]\d+)?\b|\b\d{1,4}(?:[,.]\d{1,4})?[\s]*%|\b(?:19|20)\d{2}\b|\b\d{2,}\b/', $text, $m);
    return array_unique($m[0] ?? []);
}

function extract_proper_nouns($text) {
    if (!is_string($text)) return [];
    // German proper nouns: capitalized words, allow umlauts
    // Filter: skip first-word-of-sentence false positives by requiring 4+ chars and not common words
    preg_match_all('/\b[A-ZÄÖÜ][a-zäöüß]{3,}(?:[\s-][A-ZÄÖÜ][a-zäöüß]+)*\b/u', $text, $m);
    $common = ['Diese','Diesen','Dieser','Aber','Auch','Wenn','Dabei','Dann','Dort','Doch','Hier','Auf','Mit','Bei','Für','Über','Unter','Nach','Vor','Während','Allerdings','Außerdem','Trotzdem','Deshalb','Daher','Damit','Allgemein','Eigentlich','Ebenfalls','Wieder','Erst','Erstmals','Etwa','Genau','Ganz','Mehr','Sehr','Schon','Selbst','Sogar','Wirklich','Zudem','Zumal','Zwar','Nun','Heute','Gestern','Morgen','Inzwischen','Bereits','Zugleich','Zunächst','Bisher','Eine','Einen','Einer','Eines','Einem'];
    $words = array_filter($m[0] ?? [], fn($w) => !in_array($w, $common));
    return array_values(array_unique($words));
}

function detect_media_host($url) {
    if (!is_string($url) || $url === '') return '';
    $h = parse_url($url, PHP_URL_HOST);
    return is_string($h) ? strtolower($h) : '';
}

foreach ($rows as $r) {
    $count++;
    $issues = [];
    $payload = json_decode((string)$r->ai_payload, true);
    if (!is_array($payload)) {
        $issues[] = 'no_payload';
        $findings[] = ['id'=>(int)$r->id, 'issues'=>$issues, 'title'=>substr($r->original_title, 0, 60)];
        continue;
    }

    $orig_content = (string)$r->original_content;
    $orig_excerpt = (string)($payload['_meta']['source_dossier']['primary']['excerpt'] ?? '');
    $source_text = $orig_content . ' ' . $orig_excerpt;

    $de_body = (string)($payload['languages']['de']['content'] ?? $payload['languages']['de']['body_html'] ?? '');
    $uk_body = (string)($payload['languages']['uk']['content'] ?? $payload['languages']['uk']['body_html'] ?? '');
    $en_body = (string)($payload['languages']['en']['content'] ?? $payload['languages']['en']['body_html'] ?? '');

    $de_text = strip_tags($de_body);
    $uk_text = strip_tags($uk_body);
    $en_text = strip_tags($en_body);

    // Issue 1: missing language
    if (mb_strlen($de_text) < 220) $issues[] = 'de_short_or_missing';
    if (mb_strlen($uk_text) < 220) $issues[] = 'uk_short_or_missing';
    if (mb_strlen($en_text) < 220) $issues[] = 'en_short_or_missing';

    // Issue 2: NEW numbers in DE that don't exist in source
    $source_nums = extract_numbers($source_text);
    $de_nums = extract_numbers($de_text);
    $new_in_de = array_diff($de_nums, $source_nums);
    // Filter out numbers that are years/very small
    $suspicious_de_nums = array_filter($new_in_de, function($n) {
        $clean = str_replace([',','.',' '], '', $n);
        $val = (float)$clean;
        return $val >= 100; // ignore years etc — those overlap heavily
    });
    if (count($suspicious_de_nums) > 0) {
        $issues[] = 'de_new_numbers:' . implode(',', array_slice($suspicious_de_nums, 0, 5));
    }

    // Issue 3: Numbers in DE that disagree between DE / UK / EN
    $uk_nums_clean = array_map(fn($n) => str_replace([',','.',' '], '', $n), extract_numbers($uk_text));
    $en_nums_clean = array_map(fn($n) => str_replace([',','.',' '], '', $n), extract_numbers($en_text));
    $de_nums_clean = array_map(fn($n) => str_replace([',','.',' '], '', $n), $de_nums);

    $de_large = array_filter($de_nums_clean, fn($v) => (float)$v >= 1000);
    $uk_large = array_filter($uk_nums_clean, fn($v) => (float)$v >= 1000);
    $en_large = array_filter($en_nums_clean, fn($v) => (float)$v >= 1000);
    $missing_in_uk = array_diff($de_large, $uk_large);
    $missing_in_en = array_diff($de_large, $en_large);
    if (count($missing_in_uk) > 1) {
        $issues[] = 'uk_missing_de_nums:' . implode(',', array_slice($missing_in_uk, 0, 3));
    }
    if (count($missing_in_en) > 1) {
        $issues[] = 'en_missing_de_nums:' . implode(',', array_slice($missing_in_en, 0, 3));
    }

    // Issue 4: Featured media — wiki/pexels OR off-source
    $media_url = (string)($payload['featured_media_url'] ?? $payload['media_url'] ?? '');
    $media_host = detect_media_host($media_url);
    if ($media_host !== '' && (
        str_contains($media_host, 'wikimedia') ||
        str_contains($media_host, 'pexels') ||
        str_contains($media_host, 'wikipedia')
    )) {
        $issues[] = 'media_wiki_pexels:' . $media_host;
    }

    // Issue 5: source_image vs featured — if source had image but final featured comes from somewhere unrelated
    $source_image = (string)$r->source_image_url;
    if ($source_image !== '' && $media_url !== '') {
        $source_host = detect_media_host($source_image);
        $orig_url_host = detect_media_host((string)$r->original_url);
        // If featured is NOT from source-host AND NOT from original-source-host, flag
        if ($media_host !== $source_host
            && stripos($media_host, $orig_url_host) === false
            && stripos($orig_url_host, $media_host) === false) {
            $issues[] = 'media_off_source:' . $media_host . '_vs_' . $source_host;
        }
    }

    // Issue 6: Proper nouns in DE that don't appear in source (potential hallucination)
    $source_nouns = extract_proper_nouns($source_text);
    $de_nouns = extract_proper_nouns($de_text);
    $new_de_nouns = array_diff($de_nouns, $source_nouns);
    // Only flag if there are many new nouns (1-2 is normal — natural rephrasing)
    if (count($new_de_nouns) > 5) {
        $issues[] = 'de_many_new_nouns:' . count($new_de_nouns);
    }

    if ($issues !== []) {
        $findings[] = [
            'id' => (int)$r->id,
            'cat' => (string)$r->category_final,
            'title' => mb_substr($r->original_title, 0, 60),
            'issues' => $issues,
            'media_host' => $media_host,
            'orig_url_host' => detect_media_host((string)$r->original_url),
        ];
    }
}

echo "Total published today: $count\n";
echo "Items with findings: " . count($findings) . "\n\n";

// Group by issue type
$by_issue = [];
foreach ($findings as $f) {
    foreach ($f['issues'] as $i) {
        $key = explode(':', $i)[0];
        $by_issue[$key] = ($by_issue[$key] ?? 0) + 1;
    }
}
echo "=== Issues by type ===\n";
foreach ($by_issue as $k => $v) {
    echo sprintf("  %-30s %d\n", $k, $v);
}

echo "\n=== Top 30 most concerning ===\n";
// Sort: items with media issues + many new numbers first
usort($findings, function($a, $b) {
    $score_a = 0; $score_b = 0;
    foreach ($a['issues'] as $i) {
        if (str_starts_with($i, 'media_wiki_pexels')) $score_a += 10;
        if (str_starts_with($i, 'media_off_source')) $score_a += 8;
        if (str_starts_with($i, 'de_new_numbers')) $score_a += 5;
        if (str_starts_with($i, 'de_many_new_nouns')) $score_a += 3;
        if (str_contains($i, '_short_or_missing')) $score_a += 4;
        if (str_contains($i, '_missing_de_nums')) $score_a += 3;
    }
    foreach ($b['issues'] as $i) {
        if (str_starts_with($i, 'media_wiki_pexels')) $score_b += 10;
        if (str_starts_with($i, 'media_off_source')) $score_b += 8;
        if (str_starts_with($i, 'de_new_numbers')) $score_b += 5;
        if (str_starts_with($i, 'de_many_new_nouns')) $score_b += 3;
        if (str_contains($i, '_short_or_missing')) $score_b += 4;
        if (str_contains($i, '_missing_de_nums')) $score_b += 3;
    }
    return $score_b <=> $score_a;
});
$top = array_slice($findings, 0, 30);
foreach ($top as $f) {
    echo "#$f[id] [$f[cat]] " . $f['title'] . "\n";
    echo "  issues: " . implode('; ', $f['issues']) . "\n";
}

// Save full findings to file
file_put_contents('/tmp/factcheck-' . date('Hi') . '.json', wp_json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\nFull findings: /tmp/factcheck-" . date('Hi') . ".json\n";
