<?php
require_once __DIR__ . '/../lib/provider.php';

$providerName = $_GET['provider'] ?? null;

if ($providerName === null) {
    http_response_code(400);
    echo "Provider parameter is required";
    exit;
}

if (!in_array($providerName, ['Elasticsearch', 'Laana'])) {
    http_response_code(400);
    echo "Invalid provider";
    exit;
}

// Generate provider-specific search mode help
$helpContent = '';

if ($providerName === 'Elasticsearch') {
    $helpContent = <<<HTML
<h3>Search options (Elasticsearch Provider)</h3>
<p>All searches except for "Regex" are case-insensitive. These are the choices:</p>
<ul>
  <li><span class="searchtype">Match any of the words</span>: matches sentences with any of the words in your search expression, e.g. <span class="searchterm">hale kuai</span> returns sentences containing <span class="searchterm">hale</span>, <span class="searchterm">kuai</span> or both</li>
  <li><span class="searchtype">Match all words in any order</span>: matches sentences that includes all the words in your search expression in any order, e.g. <span class="searchterm">hale kuai</span> returns sentences like <span class="searchterm">Ua koha oia i ka hale no ke kuai ana</span> as well as <span class="searchterm">Ua kuai oia i ka hale</span></li>
  <li><span class="searchtype">Match exact phrase</span>: matches your search expression exactly as entered, e.g. <span class="searchterm">hale kuai</span> returns sentences containing <span class="searchterm">hale kuai</span> in that exact order</li>
  <li><span class="searchtype">Regular expression search</span>: matches your <a class="fancy" target="_blank" href="https://www.guru99.com/regular-expressions.html">regular expression</a>, e.g. <span class="searchterm">ho\w{5}\skaua</span> to find sentences containing a 7-letter word starting with ho and followed by a space and kaua</li>
  <li><span class="searchtype">Hybrid semantic search on sentences</span>: combines keyword matching with semantic similarity search using AI embeddings to find conceptually related sentences within documents</li>
  <li><span class="searchtype">Hybrid semantic search on documents</span>: uses AI semantic search to find entire documents conceptually related to your query, returning relevant excerpts from matching documents</li>
</ul>
<p><span class="searchtype">No Diacriticals</span>: diacritical marks and 'okina are ignored. For example, searching for <span class="searchterm">ho'okipa</span> matches <span class="searchterm">hookipa</span> as well as <span class="searchterm">ho'okipa</span>; searching for <span class="searchterm">hookipa</span> returns the same results. Without No Diacriticals, searching for <span class="searchterm">ho'okipa</span> returns only results with <span class="searchterm">ho'okipa</span> while searching for <span class="searchterm">hookipa</span> matches only on <span class="searchterm">hookipa</span></p>
HTML;
} else { // Laana
    $helpContent = <<<HTML
<h3>Search options (Laana Provider)</h3>
<p>All searches except for "Regex" and "Exact" are case-insensitive. These are the choices:</p>
<ul>
  <li><span class="searchtype">Match exact phrase</span>: matches your search expression exactly as entered, e.g. <span class="searchterm">hale kuai</span> returns sentences containing <span class="searchterm">hale kuai</span> in that exact order</li>
  <li><span class="searchtype">Match any of the words</span>: matches sentences with any of the words in your search expression, e.g. <span class="searchterm">hale kuai</span> returns sentences containing <span class="searchterm">hale</span>, <span class="searchterm">kuai</span> or both</li>
  <li><span class="searchtype">Match all words in any order</span>: matches sentences that includes all the words in your search expression in any order, e.g. <span class="searchterm">hale kuai</span> returns sentences like <span class="searchterm">Ua koha oia i ka hale no ke kuai ana</span> as well as <span class="searchterm">Ua kuai oia i ka hale</span></li>
  <li><span class="searchtype">Regular expression search</span>: matches your <a class="fancy" target="_blank" href="https://www.guru99.com/regular-expressions.html">regular expression</a>, e.g. <span class="searchterm">ho\w{5}\skaua</span> to find sentences containing a 7-letter word starting with ho and followed by a space and kaua</li>
</ul>
<p><span class="searchtype">No Diacriticals</span>: diacritical marks and 'okina are ignored. For example, searching for <span class="searchterm">ho'okipa</span> matches <span class="searchterm">hookipa</span> as well as <span class="searchterm">ho'okipa</span>; searching for <span class="searchterm">hookipa</span> returns the same results. Without No Diacriticals, searching for <span class="searchterm">ho'okipa</span> returns only results with <span class="searchterm">ho'okipa</span> while searching for <span class="searchterm">hookipa</span> matches only on <span class="searchterm">hookipa</span></p>
HTML;
}

// Common help content for both providers
$commonHelp = <<<'HTML'
<p>The default sort option (selected from "Search Options") is "Random". Search results are returned in random order. These are the choices:</p>
<ul>
  <li><span class="searchtype">Random</span>: returns search results in random order</li>
  <li><span class="searchtype">Alphabetical</span>: returns search results in alphabetical order</li>
  <li><span class="searchtype">By date</span>: returns search results by source document in date order and then by sentence in alphabetical order; note: source documents without dates are not included</li>
  <li><span class="searchtype">By date descending</span>: returns search results by source document in descending date order and then by sentence in alphabetical order; note: source documents without dates are not included</li>
  <li><span class="searchtype">By source</span>: returns search results by source document in alphabetical order and then by sentence in alphabetical order</li>
  <li><span class="searchtype">By source descending</span>: returns search results by source document in descending alphabetical order and then by sentence in alphabetical order</li>
  <li><span class="searchtype">By length</span>: returns search results by sentence length in ascending order</li>
  <li><span class="searchtype">By length descending</span>: returns search results by sentence length in descending order</li>
  <li><span class="searchtype">None</span>: returns search results by the order that the sources were added to the database; this can provide a significant speed boost for Exact and Regex searches</li>
</ul>
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

echo $helpContent . $commonHelp;
