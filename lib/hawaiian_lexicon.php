<?php
/**
 * Hawaiian lexicon — word-type identification.
 *
 * hawaiian_lexicon.json holds semantic word categories (articles,
 * demonstratives, locatives, prepositions, ...), each a standard linguistic
 * class — not a category shaped by any one grammar pattern's needs. This
 * keeps the data reusable (also by non-PHP code) and means adding/removing a
 * word is a JSON edit, never a code change. Hawaiian is highly polysemous
 * (a word may be article, preposition, particle, etc. depending on context),
 * so a word may legitimately appear in more than one category, and these
 * lists are intentionally conservative stopgaps; a real dictionary /
 * morphological analyzer would replace the data file without touching this
 * code.
 *
 * Patterns in grammar_patterns.json compose categories with `+`, e.g.
 * %%articles+demonstratives+locatives%% expands to the union of those three
 * categories as a bare PCRE alternation. Structure (negation, word
 * boundaries, quantifiers) stays in the regex text itself, not baked into
 * the lexicon, so a pattern reads as grammar: e.g.
 * (?!(?:%%verbs+function_words%%)\b) — "not a known verb or function word".
 */

/** Load and cache the lexicon data file. Fails loudly if missing or invalid. */
function hw_lexicon_data(): array {
    static $data = null;
    if ($data === null) {
        $path = __DIR__ . '/hawaiian_lexicon.json';
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Hawaiian lexicon data missing: $path");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("Hawaiian lexicon data is not valid JSON: $path");
        }
    }
    return $data;
}

/**
 * Lemmas for one semantic category, e.g. hw_category_lemmas('locatives').
 *
 * The pseudo-category 'function_words' is derived, not stored: it is the
 * union of every category except 'verbs' (the one open class), so any new
 * closed-class category added to the JSON automatically becomes part of it.
 */
function hw_category_lemmas(string $name): array {
    $data = hw_lexicon_data();
    if ($name === 'function_words') {
        $lemmas = [];
        foreach ($data as $category => $words) {
            if ($category !== 'verbs') {
                $lemmas = array_merge($lemmas, $words);
            }
        }
        return array_values(array_unique($lemmas));
    }
    return $data[$name] ?? [];
}

function hw_verb_lemmas(): array {
    return hw_category_lemmas('verbs');
}
function hw_function_word_lemmas(): array {
    return hw_category_lemmas('function_words');
}

/** Verb + function-word lemmas: every word that can never be a proper noun. */
function hw_exclude_lemmas(): array {
    return array_values(array_unique(array_merge(hw_verb_lemmas(), hw_function_word_lemmas())));
}

/** Predicate: is $word (case-insensitive) a known verb or function word? Reusable anywhere. */
function hw_is_excluded_word(string $word): bool {
    static $set = null;
    if ($set === null) {
        $set = [];
        foreach (hw_exclude_lemmas() as $w) {
            $set[mb_strtolower($w)] = true;
        }
    }
    return isset($set[mb_strtolower(trim($word))]);
}

/**
 * Build a bare, word-bounded-free PCRE alternation from $lemmas (longest
 * lemma first, so a longer lemma always wins over a shorter prefix once the
 * caller adds its own \b/\s boundary around the whole alternation).
 */
function hw_alternation(array $lemmas): string {
    $alts = [];
    foreach (array_unique($lemmas) as $w) {
        $alts[] = preg_quote($w, '/');
    }
    usort($alts, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', $alts);
}

/**
 * Resolve a %%TOKEN%% expression from a pattern regex into a bare PCRE
 * alternation. TOKEN is one or more category names joined by '+', e.g.
 * "articles+demonstratives+locatives" or the derived "function_words".
 * The caller supplies all surrounding structure: (?:...)\b, (?!...), etc.
 * Returns null only if every named category is empty/unknown.
 */
function hw_lexicon_pattern(string $expr): ?string {
    $lemmas = [];
    foreach (explode('+', $expr) as $category) {
        $lemmas = array_merge($lemmas, hw_category_lemmas($category));
    }
    if (!$lemmas) {
        return null;
    }
    return hw_alternation($lemmas);
}
