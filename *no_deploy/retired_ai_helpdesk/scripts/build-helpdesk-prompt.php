<?php
/**
 * AI ヘルプデスク knowledge base 結合スクリプト (Batch-FINAL 準備)
 *
 * 使い方:
 *   php scripts/build-helpdesk-prompt.php
 *
 * 生成物:
 *   scripts/output/helpdesk-prompt-tenant.txt    (テナント側ヘルプデスク用)
 *   scripts/output/helpdesk-prompt-internal.txt  (POSLA運営側ヘルプデスク用)
 *
 * tenant 側: docs/manual/tenant/*.md を結合 (顧客向け)
 * internal 側: 上記 + docs/manual/internal/*.md + voice-commands.md (運営向け、技術詳細込み)
 *
 * ai-assistant.js からこのテキストをシステムプロンプトとして Gemini に渡す想定。
 */

$baseDir = dirname(__DIR__);
$outputDir = $baseDir . '/scripts/output';
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

// ---------- Tenant 向け ----------
$tenantFiles = [];
$tenantDir = $baseDir . '/docs/manual/tenant';
foreach (glob($tenantDir . '/*.md') as $f) {
    if (basename($f) === 'index.md') continue;
    $tenantFiles[] = $f;
}
sort($tenantFiles);

// voice-commands + operations (Part 0-8) も含める
$voiceFile = $baseDir . '/docs/voice-commands.md';
$operationsFiles = [];
$operationsDir = $baseDir . '/docs/manual/operations';
if (is_dir($operationsDir)) {
    foreach (glob($operationsDir . '/*.md') as $f) {
        $operationsFiles[] = $f;
    }
    sort($operationsFiles);
}

$tenantExtras = array_merge([$voiceFile], $operationsFiles);
$tenantOut = _buildPrompt('TENANT', $tenantFiles, $tenantExtras);
file_put_contents($outputDir . '/helpdesk-prompt-tenant.txt', $tenantOut);
$tenantRag = _buildRagIndex('TENANT', $tenantFiles, $tenantExtras);
file_put_contents(
    $outputDir . '/helpdesk-rag-tenant.json',
    json_encode($tenantRag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);

// ---------- Internal (POSLA運営) 向け ----------
$internalFiles = $tenantFiles; // tenant を含む
$internalDir = $baseDir . '/docs/manual/internal';
foreach (glob($internalDir . '/*.md') as $f) {
    if (basename($f) === 'index.md') continue;
    $internalFiles[] = $f;
}

$specFile = $baseDir . '/docs/SYSTEM_SPECIFICATION.md';

$internalExtras = array_merge([$voiceFile, $specFile], $operationsFiles);
$internalOut = _buildPrompt('INTERNAL', $internalFiles, $internalExtras);
file_put_contents($outputDir . '/helpdesk-prompt-internal.txt', $internalOut);
$internalRag = _buildRagIndex('INTERNAL', $internalFiles, $internalExtras);
file_put_contents(
    $outputDir . '/helpdesk-rag-internal.json',
    json_encode($internalRag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);

// ---------- サマリ ----------
echo "=== AI Helpdesk knowledge base build complete ===\n";
echo "Tenant  : " . strlen($tenantOut) . " bytes / " . count($tenantFiles) . " chapters + voice\n";
echo "Internal: " . strlen($internalOut) . " bytes / " . count($internalFiles) . " chapters + voice + spec\n";
echo "Tenant RAG chunks  : " . count($tenantRag['chunks']) . "\n";
echo "Internal RAG chunks: " . count($internalRag['chunks']) . "\n";
echo "Output: $outputDir\n";


function _buildPrompt($scope, $files, $extraFiles) {
    $out = "# POSLA AI HELPDESK KNOWLEDGE BASE ($scope)\n\n";
    $out .= "Generated: " . date('c') . "\n";
    $out .= "Source: matsunoya-mt repository\n\n";
    $out .= "==============================================\n";
    $out .= "# あなたは POSLA ヘルプデスクです\n";
    $out .= "==============================================\n\n";
    $out .= "ユーザー (飲食店オーナー / 店長 / スタッフ / " . ($scope === 'INTERNAL' ? 'POSLA運営' : '') . ") からの質問に、\n";
    $out .= "以下のマニュアル内容を根拠に回答してください。\n";
    $out .= "- マニュアルに書かれていないことは推測せず「マニュアルに記載なし」と答える\n";
    $out .= "- 具体的な操作手順は該当章の該当番号を明示する (例: 「24.3 ガント台帳」を参照)\n";
    $out .= "- スタッフ向けには専門用語を避け平易な言葉で説明\n";
    $out .= ($scope === 'INTERNAL' ? "- 運営向けには技術詳細 (API / DB / ファイルパス) も含めてよい\n" : "") . "\n";
    $out .= "==============================================\n";
    $out .= "# マニュアル本体\n";
    $out .= "==============================================\n\n";

    foreach ($files as $f) {
        if (!is_readable($f)) continue;
        $name = basename($f);
        $content = file_get_contents($f);
        // internal scope 以外では 「技術補足」ブロック除去
        if ($scope !== 'INTERNAL') {
            $content = preg_replace('/## 💼 技術補足.*?(?=\n## [^💼]|\z)/s', '', $content);
        }
        $out .= "\n\n===== FILE: $name =====\n\n";
        $out .= $content;
    }

    foreach ($extraFiles as $f) {
        if (!is_readable($f)) continue;
        $name = basename($f);
        $out .= "\n\n===== FILE: $name =====\n\n";
        $out .= file_get_contents($f);
    }

    return $out;
}

function _buildRagIndex($scope, $files, $extraFiles) {
    $chunks = [];
    $allFiles = array_merge($files, $extraFiles);
    $id = 1;

    foreach ($allFiles as $f) {
        if (!is_readable($f)) continue;
        $content = file_get_contents($f);
        if ($content === false || $content === '') continue;
        foreach (_extractChunksFromMarkdown($f, $content) as $chunk) {
            $chunk['id'] = $scope . '-' . $id;
            $chunk['scope'] = $scope;
            $chunks[] = $chunk;
            $id++;
        }
    }

    return [
        'scope' => $scope,
        'generated_at' => date('c'),
        'chunk_count' => count($chunks),
        'chunks' => $chunks,
    ];
}

function _extractChunksFromMarkdown($file, $content) {
    $basename = basename($file);
    $lines = preg_split('/\r\n|\n|\r/', $content);
    $chunks = [];
    $headingTrail = [];
    $buffer = [];
    $currentSection = $basename;

    foreach ($lines as $line) {
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            _flushChunkBuffer($chunks, $file, $currentSection, $buffer);
            $level = strlen($m[1]);
            $title = trim($m[2]);
            $headingTrail = array_slice($headingTrail, 0, max(0, $level - 1));
            $headingTrail[$level - 1] = $title;
            $currentSection = trim(implode(' > ', $headingTrail));
            $buffer = [$line];
            continue;
        }
        $buffer[] = $line;
    }

    _flushChunkBuffer($chunks, $file, $currentSection, $buffer);
    return $chunks;
}

function _flushChunkBuffer(&$chunks, $file, $section, &$buffer) {
    $text = trim(implode("\n", $buffer));
    $buffer = [];
    if ($text === '') {
        return;
    }
    foreach (_splitChunkText($text, 2200) as $part) {
        $chunks[] = [
            'file' => basename($file),
            'section' => $section,
            'text' => $part,
        ];
    }
}

function _splitChunkText($text, $maxChars) {
    $parts = [];
    $paragraphs = preg_split("/\n\s*\n/", $text);
    $current = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') continue;

        if ($current !== '' && strlen($current . "\n\n" . $paragraph) > $maxChars) {
            $parts[] = $current;
            $current = '';
        }

        if (strlen($paragraph) > $maxChars) {
            foreach (_splitLongParagraph($paragraph, $maxChars) as $piece) {
                if ($current !== '' && strlen($current . "\n\n" . $piece) > $maxChars) {
                    $parts[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $piece : ($current . "\n\n" . $piece);
            }
            continue;
        }

        $current = $current === '' ? $paragraph : ($current . "\n\n" . $paragraph);
    }

    if ($current !== '') {
        $parts[] = $current;
    }

    return $parts;
}

function _splitLongParagraph($paragraph, $maxChars) {
    $parts = [];
    $lines = preg_split('/\n/', $paragraph);
    $current = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (strlen($line) > $maxChars) {
            if ($current !== '') {
                $parts[] = $current;
                $current = '';
            }
            foreach (_mbSplitText($line, $maxChars) as $piece) {
                $parts[] = $piece;
            }
            continue;
        }

        if ($current !== '' && strlen($current . "\n" . $line) > $maxChars) {
            $parts[] = $current;
            $current = '';
        }
        $current = $current === '' ? $line : ($current . "\n" . $line);
    }

    if ($current !== '') {
        $parts[] = $current;
    }

    return $parts;
}

function _mbSplitText($text, $chunkSize) {
    $parts = [];
    $len = mb_strlen($text, 'UTF-8');
    $offset = 0;

    while ($offset < $len) {
        $parts[] = mb_substr($text, $offset, $chunkSize, 'UTF-8');
        $offset += $chunkSize;
    }

    return $parts;
}
