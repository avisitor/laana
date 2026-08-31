<?php

require_once __DIR__ . '/hawaiian_lexicon.php';

function getGrammarPatterns() {
    $json = file_get_contents(__DIR__ . '/grammar_patterns.json');
    $patterns = json_decode($json, true);

    if (!$patterns) {
        return [];
    }

    // Patterns may embed %%TOKEN%% lexicon fragments (see hawaiian_lexicon.php);
    // substitute each from the JSON-backed lexicon so word lists stay in data.
    foreach ($patterns as $key => &$data) {
        if (!isset($data['regex'])) {
            continue;
        }
        if (preg_match_all('/%%([\w+]+)%%/', $data['regex'], $m)) {
            foreach (array_unique($m[1]) as $token) {
                $frag = hw_lexicon_pattern($token);
                if ($frag === null) {
                    continue;
                }
                $data['regex'] = str_replace('%%' . $token . '%%', $frag, $data['regex']);
            }
        }
        // Add delimiters and flags (u = unicode, i = case insensitive)
        $data['regex'] = '/' . $data['regex'] . '/ui';
    }

    return $patterns;
}
?>
