<?php

namespace Noiiolelo;

/**
 * Renders the "Search options" help block for a search provider: its modes,
 * the No Diacriticals setting (when supported), and the sort/year-range/link
 * paragraphs (filtered to the provider's supported sort options). Shared by
 * the overview page (overview.html) and the provider-help endpoint
 * (ops/getProviderHelp.php).
 */
class ProviderHelp
{
    /** Detail sentence for each search-mode key; unknown modes fall back to their description. */
    private const MODE_DETAILS = [
        'exact' => 'matches your search expression exactly as entered. Whether or not Hawaiian characters are treated the same as characters without diacritical marks depends on the No Diacriticals setting below. If No Diacriticals is not set, <span class="searchterm">hale kuai</span> returns sentences containing <span class="searchterm">hale kuai</span> but not <span class="searchterm">hale kū‘ai</span> while if it is set, both are returned',
        'phrase' => 'matches sentences containing your search expression exactly as entered, e.g. <span class="searchterm">hale kuai</span> returns sentences containing <span class="searchterm">hale kuai</span> in that exact order',
        'any' => 'matches sentences with any of the words in your search expression, e.g. <span class="searchterm">hale kuai</span> returns sentences containing <span class="searchterm">hale</span>, <span class="searchterm">kuai</span> or both',
        'match' => 'matches sentences with any of the words in your search expression, e.g. <span class="searchterm">hale kuai</span> returns sentences containing <span class="searchterm">hale</span>, <span class="searchterm">kuai</span> or both',
        'all' => 'matches sentences that includes all the words in your search expression in any order, e.g. <span class="searchterm">hale kuai</span> returns sentences like <span class="searchterm">Ua koha oia i ka hale no ke kuai ana</span> as well as <span class="searchterm">Ua kuai oia i ka hale</span>',
        'matchall' => 'matches sentences that includes all the words in your search expression in any order, e.g. <span class="searchterm">hale kuai</span> returns sentences like <span class="searchterm">Ua koha oia i ka hale no ke kuai ana</span> as well as <span class="searchterm">Ua kuai oia i ka hale</span>',
        'near' => 'finds sentences where words appear next to each other in the specified order',
        'regex' => 'matches your <a class="fancy" target="_blank" href="https://www.guru99.com/regular-expressions.html">regular expression</a>, e.g. <span class="searchterm">ho\w{5}\skaua</span> to find sentences containing a 7-letter word starting with ho and followed by a space and kaua',
        'hybrid' => 'combines keyword matching with semantic similarity search using AI embeddings to find conceptually related sentences',
        'hybriddoc' => 'uses AI semantic search to find entire documents conceptually related to your query, returning relevant excerpts from matching documents',
    ];

    /** Detail sentence for each supported sort-option value (see SearchProviderInterface::getAvailableSortOptions). */
    private const SORT_DETAILS = [
        'rand' => 'returns search results in random order',
        'alpha' => 'returns search results in alphabetical order',
        'alpha desc' => 'returns search results in descending alphabetical order',
        'date' => 'returns search results by source document in date order and then by sentence in alphabetical order; note: source documents without dates are not included',
        'date desc' => 'returns search results by source document in descending date order and then by sentence in alphabetical order; note: source documents without dates are not included',
        'source' => 'returns search results by source document in alphabetical order and then by sentence in alphabetical order',
        'source desc' => 'returns search results by source document in descending alphabetical order and then by sentence in alphabetical order',
        'length' => 'returns search results by sentence length in ascending order',
        'length desc' => 'returns search results by sentence length in descending order',
        'none' => 'returns search results by the order that the sources were added to the database; this can provide a significant speed boost for Exact and Regex searches',
        'score' => 'returns search results by relevance score, most relevant first',
    ];

    private const NO_DIACRITICALS_HTML = <<<'HTML'
<p><span class="searchtype">No Diacriticals</span>: diacritical marks and ‘okina are ignored. For example, searching for <span class="searchterm">ho‘okipa</span> matches <span class="searchterm">hookipa</span> as well as <span class="searchterm">ho‘okipa</span>; searching for <span class="searchterm">hookipa</span> returns the same results. Without No Diacriticals, searching for <span class="searchterm">ho‘okipa</span> returns only results with <span class="searchterm">ho‘okipa</span> while searching for <span class="searchterm">hookipa</span> matches only on <span class="searchterm">hookipa</span></p>
HTML;

    private const COMMON_TAIL_HTML = <<<'HTML'
<p>The sources searched can be limited to a date range</p>
<ul>
  <li><span class="searchtype">From year</span>: only sources from this or later years are searched</li>
  <li><span class="searchtype">To year</span>: only sources prior to or in this year are searched; e.g <span class="searchtype">From year</span> <span class="searchterm">1990</span> and <span class="searchtype">To year</span> <span class="searchterm">2030</span> would only use recent sources while <span class="searchtype">From year</span> <span class="searchterm">1830</span> and <span class="searchtype">To year</span> <span class="searchterm">1899</span> would only use 19th century sources</li>
</ul>
<p>A search term must be at least three characters long; <span class="searchterm">ka</span> does not return any matches.</p>
<p>For each sentence found, the following links are provided:</p>
  <ul>
    <li><span class="searchtype">Source name</span>: takes you to the original source website</li>
    <li><span class="searchtype">Snapshot</span>: a snapshot of the original source</li>
    <li><span class="searchtype">Context</span>: location of the sentence in the snapshot, if possible</li>
    <li><span class="searchtype">Simplified</span>: location of the sentence in the plain text of the snapshot</li>
    <li><span class="searchtype">Translate</span>: a Google Translate page for the sentence</li>
  </ul>
HTML;

    public static function getSearchOptionsHtml(SearchProviderInterface $provider, string $title = 'Search options'): string
    {
        $modes = $provider->getAvailableSearchModes();
        $html = '<h3>' . htmlspecialchars($title, ENT_QUOTES) . '</h3>';

        if ($modes !== []) {
            $defaultMode = array_key_first($modes);
            $html .= '<div id="search-option-help"><p>The default search option (selected from '
                . '"Search Options") is "' . htmlspecialchars((string)($modes[$defaultMode] ?? ''),
                    ENT_QUOTES)
                . '". All searches except for "Regex"'
                . ($provider->getName() === 'MySQL' ? ' and "Exact"' : '')
                . ' are case-insensitive. These are the choices:</p>';
            $html .= '<ul>';
            foreach ($modes as $mode => $description) {
                $detail = self::MODE_DETAILS[$mode]
                    ?? 'matches sentences as described by the provider';
                $html .= '<li><span class="searchtype">' . htmlspecialchars((string)$description, ENT_QUOTES)
                    . '</span>: ' . $detail . '</li>';
            }
            $html .= '</ul>';
        }

        if ($provider->providesNoDiacritics()) {
            $html .= self::NO_DIACRITICALS_HTML;
        }

        $sortOptions = $provider->getAvailableSortOptions();
        if ($sortOptions !== []) {
            $defaultSort = array_key_exists('rand', $sortOptions) ? 'rand' : (string)array_key_first($sortOptions);
            $html .= '<p>The default sort option (selected from "Search Options") is "'
                . htmlspecialchars((string)$sortOptions[$defaultSort], ENT_QUOTES)
                . '". Search results are returned according to the chosen sort. These are the choices:</p>';
            $html .= '<ul>';
            foreach ($sortOptions as $sortValue => $sortLabel) {
                $detail = self::SORT_DETAILS[$sortValue]
                    ?? 'returns search results in the order this provider defines for this option';
                $html .= '<li><span class="searchtype">' . htmlspecialchars((string)$sortLabel, ENT_QUOTES)
                    . '</span>: ' . $detail . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= self::COMMON_TAIL_HTML;

        return $html . "\n";
    }
}
