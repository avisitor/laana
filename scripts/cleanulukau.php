<?php

libxml_use_internal_errors(true);

// 🧼 Clean lines using same logic as render.js
function cleanText($text) {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s{2,}/u', ' ', $text);
    $lines = preg_split('/\R/u', $text);

    $filtered = array_filter($lines, function ($line) {
        $line = trim($line);
        if ($line === '') return false;
        if (preg_match('/^\(No text\)$/iu', $line)) return false;
        if (preg_match('/^Page \d{1,4}$/u', $line)) return false;
        if (preg_match('/^[\s()\/\'0-9;.,–—\-]+$/u', $line)) return false;
        if (preg_match('/^\s*[ivxlcdmIVXLCDM]+\s*$/u', $line)) return false;
        return true;
    });

    return implode("\n\n", $filtered);
}

$inputPath = $argv[1] ?? '';
if (!$inputPath || !file_exists($inputPath)) {
    fwrite(STDERR, "❌ Usage: php extract_clean_text.php <input.html>\n");
    exit(1);
}

// ✅ Read content as UTF-8
$html = file_get_contents($inputPath);
$html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$xpath = new DOMXPath($dom);

$output = '';
$divs = $xpath->query('//div[contains(@class, "ulukaupagetextview")]');

foreach ($divs as $div) {
    $sectionText = '';

    foreach ($div->getElementsByTagName('span') as $span) {
        $text = $span->textContent;
        $text = str_replace(["<br>", "<br/>", "<br />"], "\n", $text); // sanitize legacy
        $sectionText .= trim($text) . "\n";
    }

    $cleaned = cleanText($sectionText);
    if ($cleaned !== '') {
        $output .= $cleaned . "\n\n";
    }
}

// ✅ Output clean text in UTF-8
echo $output;
