<?php
/**
 * AI ヘルプデスク用 軽量 RAG ヘルパー
 *
 * docs から事前生成した chunk index JSON を読み込み、
 * 質問に近い chunk を単純な lexical scoring で選択する。
 */

if (!function_exists('helpdesk_rag_load_index')) {
    function helpdesk_rag_load_index($path)
    {
        if (!is_readable($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['chunks']) || !is_array($data['chunks'])) {
            return null;
        }
        return $data;
    }
}

if (!function_exists('helpdesk_rag_select_chunks')) {
    function helpdesk_rag_select_chunks($index, $query, $limit)
    {
        $chunks = isset($index['chunks']) && is_array($index['chunks']) ? $index['chunks'] : [];
        $queryNorm = helpdesk_rag_normalize_text($query);
        $terms = helpdesk_rag_extract_terms($queryNorm);
        $ngrams = helpdesk_rag_extract_ngrams($queryNorm, 2, 48);
        $scored = [];
        $selected = [];
        $perFile = [];
        $i = 0;

        foreach ($chunks as $chunk) {
            $score = helpdesk_rag_score_chunk($chunk, $queryNorm, $terms, $ngrams);
            if ($score <= 0) {
                continue;
            }
            $chunk['_score'] = $score;
            $chunk['_order'] = $i;
            $scored[] = $chunk;
            $i++;
        }

        usort($scored, function ($a, $b) {
            if ($a['_score'] === $b['_score']) {
                return $a['_order'] <=> $b['_order'];
            }
            return $b['_score'] <=> $a['_score'];
        });

        if ($limit < 1) {
            $limit = 1;
        }
        foreach ($scored as $chunk) {
            $file = isset($chunk['file']) ? $chunk['file'] : 'unknown';
            if (!isset($perFile[$file])) {
                $perFile[$file] = 0;
            }
            if ($perFile[$file] >= 2) {
                continue;
            }
            $selected[] = $chunk;
            $perFile[$file]++;
            if (count($selected) >= $limit) {
                break;
            }
        }
        return $selected;
    }
}

if (!function_exists('helpdesk_rag_format_chunks')) {
    function helpdesk_rag_format_chunks($chunks)
    {
        $out = '';
        $sources = [];
        $i = 1;

        foreach ($chunks as $chunk) {
            $file = isset($chunk['file']) ? $chunk['file'] : 'unknown';
            $section = isset($chunk['section']) ? $chunk['section'] : '';
            $text = isset($chunk['text']) ? trim((string)$chunk['text']) : '';
            $out .= "----- CHUNK {$i} -----\n";
            $out .= "FILE: {$file}\n";
            if ($section !== '') {
                $out .= "SECTION: {$section}\n";
            }
            $out .= $text . "\n\n";
            $sources[] = [
                'file' => $file,
                'section' => $section,
            ];
            $i++;
        }

        return [
            'prompt' => trim($out),
            'sources' => $sources,
        ];
    }
}

if (!function_exists('helpdesk_rag_format_history')) {
    function helpdesk_rag_format_history($history, $maxTurns)
    {
        $out = '';
        $slice = [];

        if (!is_array($history)) {
            return '';
        }
        if ($maxTurns < 1) {
            $maxTurns = 1;
        }

        $slice = array_slice($history, -$maxTurns);
        foreach ($slice as $entry) {
            $role = isset($entry['role']) ? (string)$entry['role'] : '';
            $text = isset($entry['text']) ? trim((string)$entry['text']) : '';
            if ($text === '') {
                continue;
            }
            if ($role !== 'user' && $role !== 'ai') {
                continue;
            }
            $out .= ($role === 'user' ? '【ユーザー】' : '【AI】') . $text . "\n";
        }
        return trim($out);
    }
}

if (!function_exists('helpdesk_rag_score_chunk')) {
    function helpdesk_rag_score_chunk($chunk, $queryNorm, $terms, $ngrams)
    {
        $file = isset($chunk['file']) ? (string)$chunk['file'] : '';
        $section = isset($chunk['section']) ? (string)$chunk['section'] : '';
        $text = isset($chunk['text']) ? (string)$chunk['text'] : '';
        $haystack = helpdesk_rag_normalize_text($file . ' ' . $section . ' ' . $text);
        $score = 0;
        $hits = 0;

        if ($queryNorm === '' || $haystack === '') {
            return 0;
        }

        if (mb_strpos($haystack, $queryNorm) !== false) {
            $score += 240;
        }

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            if (mb_strpos($haystack, $term) !== false) {
                $hits++;
                $score += min(80, max(14, mb_strlen($term) * 4));
                if (mb_strpos(helpdesk_rag_normalize_text($section), $term) !== false) {
                    $score += 18;
                }
                if (mb_strpos(helpdesk_rag_normalize_text($file), $term) !== false) {
                    $score += 14;
                }
            }
        }

        $score += helpdesk_rag_domain_file_boost($queryNorm, $file, $section);

        if (!empty($terms) && $hits === 0 && $score < 140) {
            return 0;
        }

        foreach ($ngrams as $ngram) {
            if ($ngram === '') {
                continue;
            }
            if (mb_strpos($haystack, $ngram) !== false) {
                $score += 1;
            }
        }

        if ($hits === 0 && $score < 12) {
            return 0;
        }

        return $score;
    }
}

if (!function_exists('helpdesk_rag_extract_terms')) {
    function helpdesk_rag_extract_terms($text)
    {
        $terms = [];
        $pieces = [];
        $i = 0;

        if ($text === '') {
            return $terms;
        }

        $pieces = preg_split('/[\s　,，、。:：;；!！?？\(\)\[\]「」『』]+/u', $text);
        foreach ($pieces as $piece) {
            $subParts = preg_split('/(?:について|とは|って|から|まで|より|です|ます|ください|教えて|なぜ|どうやって|どこで|どこ|いつ|何が|何を|何|は|が|を|に|で|と|の|へ|や|も)+/u', $piece);
            foreach ($subParts as $term) {
                $term = trim($term);
                if ($term === '') {
                    continue;
                }
                if (mb_strlen($term) < 2) {
                    continue;
                }
                $terms[] = $term;
            }
        }

        $terms = array_values(array_unique($terms));
        usort($terms, function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });
        return array_slice($terms, 0, 24);
    }
}

if (!function_exists('helpdesk_rag_extract_ngrams')) {
    function helpdesk_rag_extract_ngrams($text, $n, $maxItems)
    {
        $items = [];
        $clean = preg_replace('/[\s　\p{P}\p{S}]+/u', '', $text);
        $len = mb_strlen($clean);
        $i = 0;

        if ($clean === '' || $len < $n) {
            return $items;
        }

        for ($i = 0; $i <= $len - $n; $i++) {
            $items[] = mb_substr($clean, $i, $n);
        }
        $items = array_values(array_unique($items));
        if ($maxItems > 0) {
            $items = array_slice($items, 0, $maxItems);
        }
        return $items;
    }
}

if (!function_exists('helpdesk_rag_normalize_text')) {
    function helpdesk_rag_normalize_text($text)
    {
        $text = mb_strtolower((string)$text, 'UTF-8');
        $text = preg_replace('/[\r\n\t]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}

if (!function_exists('helpdesk_rag_domain_file_boost')) {
    function helpdesk_rag_domain_file_boost($queryNorm, $file, $section)
    {
        $boost = 0;
        $target = helpdesk_rag_normalize_text($file . ' ' . $section);
        $rules = [
            [
                'pattern' => '/guest_alias|ゲスト名|ニックネーム/u',
                'files' => ['17-customer.md', 'part7-billing.md', '04-payment.md'],
            ],
            [
                'pattern' => '/セルフ.*会計|レジ.*会計|cashier|self|partial|割り勘/u',
                'files' => ['17-customer.md', '08-cashier.md', '04-payment.md', 'part7-billing.md'],
            ],
            [
                'pattern' => '/line|ライン|予約通知|reminder|takeout_ready|外部連携/u',
                'files' => ['15-owner.md', '16-settings.md', '24-reservations.md', 'part6-settings.md', '01-posla-admin.md'],
            ],
            [
                'pattern' => '/在庫|ingredient|stock_consumed_at|inventory/u',
                'files' => ['11-inventory.md', '05-operations.md', '04-payment.md'],
            ],
            [
                'pattern' => '/kds|expeditor|厨房/u',
                'files' => ['07-kds.md', '23-kds-devices.md', 'part1-daily-operations.md'],
            ],
            [
                'pattern' => '/週次サマリー|weekly|レポート|売上/u',
                'files' => ['03-dashboard.md', '13-reports.md', 'part4-reports.md'],
            ],
        ];
        $i = 0;
        foreach ($rules as $rule) {
            if (!preg_match($rule['pattern'], $queryNorm)) {
                continue;
            }
            for ($i = 0; $i < count($rule['files']); $i++) {
                if (mb_strpos($target, helpdesk_rag_normalize_text($rule['files'][$i])) !== false) {
                    $boost += 140;
                }
            }
        }
        return $boost;
    }
}
