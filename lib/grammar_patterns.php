<?php

function getGrammarPatterns() {
    $json = file_get_contents(__DIR__ . '/grammar_patterns.json');
    $patterns = json_decode($json, true);
    
    if (!$patterns) {
        return [];
    }
    
    // Convert raw regex strings to PHP PCRE format
    foreach ($patterns as $key => &$data) {
        // Add delimiters and flags (u = unicode, i = case insensitive)
        $data['regex'] = '/' . $data['regex'] . '/ui';
    }
    
    return $patterns;
}
?>
